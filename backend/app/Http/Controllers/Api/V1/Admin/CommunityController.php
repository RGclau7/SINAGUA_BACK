<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Accounts\AccountInvitations;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Community;
use App\Models\Roles;
use App\Models\User;
use App\Security\SecurityPin;
use App\Support\ApiResponse;
use App\Support\PersonData;
use App\Tenancy\CurrentCommunity;
use App\Users\UserChangePublisher;
use App\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Solo superadministrador: alta de comunidades y de su primer administrador.
 * No expone movimientos de ninguna comunidad.
 */
class CommunityController extends Controller
{
    public function __construct(
        private readonly CurrentCommunity $context,
        private readonly UserChangePublisher $publisher,
        private readonly SecurityPin $pin,
        private readonly AccountInvitations $invitations,
    ) {}

    /**
     * Tabla de comunidades: nombre y ubicación, administrador asignado (nombre y teléfono),
     * cuántos usuarios tiene y si está activa. El buscador y los filtros van en el escritorio.
     */
    public function index(): JsonResponse
    {
        $communities = Community::query()->orderBy('name')->get();
        $counts = User::query()->whereNotNull('community_id')
            ->selectRaw('community_id, count(*) as total')->groupBy('community_id')->pluck('total', 'community_id');
        $admins = User::role(Roles::ADMIN)->whereNotNull('community_id')->orderByDesc('is_active')->orderByRaw('case when activated_at is null then 1 else 0 end')->orderBy('name')->get()
            ->groupBy('community_id');

        return ApiResponse::ok($communities->map(fn (Community $c) => [
            ...$this->present($c),
            'users_count' => (int) ($counts[$c->id] ?? 0),
            'admin' => $this->presentAdmin($admins[$c->id] ?? collect()),
        ])->all());
    }

    /** Personas de una comunidad, para elegir al nuevo administrador (sin movimientos). */
    public function users(string $id): JsonResponse
    {
        $community = Community::findOrFail($id);

        return ApiResponse::ok(User::where('community_id', $community->id)->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'email' => $u->email,
                'role' => $u->panel(),
                'is_active' => (bool) $u->is_active,
                'status' => $u->activated_at === null ? 'pending' : 'active',
            ])->values()->all());
    }

    /**
     * Cambia el administrador de una comunidad. Pide el PIN de seguridad del superadministrador.
     * El administrador anterior se desactiva o se queda como cobrador, según se elija.
     */
    public function changeAdmin(Request $request, string $id): JsonResponse
    {
        $community = Community::findOrFail($id);
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'pin' => ['required', 'string'],
            'previous' => ['sometimes', 'in:deactivate,cobrador'],
        ], [
            'user_id.required' => 'Elija a la persona que será el nuevo administrador.',
            'pin.required' => 'Escriba su PIN de seguridad.',
        ]);

        $super = $request->user();
        $this->pin->verify($super, $data['pin']);

        $new = User::where('community_id', $community->id)->find($data['user_id'])
            ?? throw new ApiException('not_found', 'Esa persona no es de esta comunidad.', 404);
        if (! $new->is_active) {
            throw new ApiException('user_inactive', 'Esa cuenta está desactivada; elija otra persona.', 422);
        }
        if ($new->activated_at === null) {
            throw new ApiException('user_pending', 'Esa persona todavía no activa su cuenta con el PIN del correo.', 422);
        }
        $previousMode = $data['previous'] ?? 'deactivate';

        $result = DB::transaction(fn () => $this->context->runAsSystem(function () use ($community, $new, $previousMode, $super) {
            $previous = User::role(Roles::ADMIN)->where('community_id', $community->id)->where('id', '!=', $new->id)->get();
            foreach ($previous as $old) {
                // Deja de ser administrador: queda como cobrador y, si así se eligió, desactivado.
                $old->syncRoles([Role::findOrCreate(Roles::COBRADOR, 'web')]);
                if ($previousMode === 'deactivate') {
                    $old->is_active = false;
                    $old->revokeAllSessions();
                }
                $old->touch();
                $old->save();
                $this->publisher->publish($old, 'update');
            }

            $new->syncRoles([Role::findOrCreate(Roles::ADMIN, 'web')]);
            $new->touch();
            $this->publisher->publish($new, 'update');

            AuditLog::record('community.admin_changed', [
                'community_id' => $community->id,
                'user_id' => $super->id,
                'entity' => 'communities',
                'entity_id' => $community->id,
                'data' => [
                    'admin_id' => $new->id,
                    'admin_name' => $new->name,
                    'previous' => $previous->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
                    'previous_mode' => $previousMode,
                ],
            ]);

            return $previous;
        }));

        $count = $result->count();

        return ApiResponse::ok([
            'admin' => ['id' => $new->id, 'name' => $new->name, 'phone' => $new->phone, 'email' => $new->email],
            'previous_count' => $count,
        ]);
    }

    /**
     * Alta por correo de un administrador o cobrador: la cuenta queda pendiente y le llega un PIN
     * de 6 números para activarla. Si el correo ya estaba pendiente, se le manda un PIN nuevo.
     */
    public function invite(Request $request, string $id): JsonResponse
    {
        $community = Community::findOrFail($id);
        $input = $request->all();
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', 'in:'.implode(',', AccountInvitations::ROLES)],
        ], [
            'name.required' => 'Escriba el nombre completo.',
            'name.min' => 'Escriba el nombre completo.',
            'email.required' => 'Escriba el correo.',
            'email.email' => 'El correo no es válido.',
            'role.required' => 'Elija si será administrador o cobrador.',
            'role.in' => 'Elija si será administrador o cobrador.',
        ])->validate();

        $user = $this->invitations->invite($community, preg_replace('/\s+/u', ' ', trim($data['name'])), $data['email'], $data['role'], $request->user());

        return ApiResponse::ok([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->panel(),
            'status' => 'pending',
            'expires_hours' => (int) config('sinagua.codes.activation_hours'),
        ], 201);
    }

    /** Desactiva la comunidad: nadie de ella entra ni sincroniza hasta que se reactive. */
    public function deactivate(Request $request, string $id): JsonResponse
    {
        return $this->setActive($request, $id, false);
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        return $this->setActive($request, $id, true);
    }

    private function setActive(Request $request, string $id, bool $active): JsonResponse
    {
        $community = Community::findOrFail($id);
        if ($community->is_active !== $active) {
            DB::transaction(fn () => $this->context->runAsSystem(function () use ($community, $active, $request) {
                $community->forceFill(['is_active' => $active, 'deactivated_at' => $active ? null : now()])->save();
                if (! $active) {
                    // Las sesiones abiertas dejan de servir en su siguiente petición.
                    AuthSession::whereIn('user_id', User::where('community_id', $community->id)->select('id'))
                        ->whereNull('revoked_at')->update(['revoked_at' => now()]);
                }
                AuditLog::record($active ? 'community.activated' : 'community.deactivated', [
                    'community_id' => $community->id,
                    'user_id' => $request->user()->id,
                    'entity' => 'communities',
                    'entity_id' => $community->id,
                    'data' => ['name' => $community->name],
                ]);
            }));
        }

        return ApiResponse::ok($this->present($community->fresh()));
    }

    /** ¿Ya tiene PIN de seguridad? (para pedir que lo cree la primera vez). */
    public function pinStatus(Request $request): JsonResponse
    {
        return ApiResponse::ok(['is_set' => $this->pin->isSet($request->user())]);
    }

    /** Crea o cambia el PIN de seguridad (pide la contraseña de la cuenta). */
    public function setPin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'pin' => ['required', 'string'],
            'pin_confirmation' => ['required', 'same:pin'],
        ], [
            'password.required' => 'Escriba la contraseña de su cuenta.',
            'pin.required' => 'Escriba el PIN de 6 números.',
            'pin_confirmation.required' => 'Repita el PIN.',
            'pin_confirmation.same' => 'Los dos PIN no son iguales.',
        ]);

        $this->pin->set($request->user(), $data['password'], $data['pin']);

        return ApiResponse::ok(['is_set' => true]);
    }

    /**
     * Alta de comunidad con sus datos de ubicación y contacto, y su primer administrador
     * con registro completo.
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->all();
        $input['contact_phone'] = PersonData::normalizePhone($input['contact_phone'] ?? null);
        $input['admin'] = PersonData::prepare((array) ($input['admin'] ?? []));
        if (isset($input['admin']['email']) && is_string($input['admin']['email'])) {
            $input['admin']['email'] = mb_strtolower(trim($input['admin']['email']));
        }

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:160', 'unique:communities,name'],
            'state' => ['required', 'string', 'max:80'],
            'municipality' => ['required', 'string', 'max:120'],
            'locality' => ['required', 'string', 'min:3', 'max:255'],
            'zone' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'regex:'.PersonData::PHONE_PATTERN],
            'contact_email' => ['nullable', 'email', 'max:190'],
            ...PersonData::rules(true, 'admin.'),
            'admin.email' => ['required', 'email', 'max:190', 'unique:users,email'],
            // Sin contraseña, el administrador recibe un PIN por correo para activar su cuenta.
            'admin.password' => ['nullable', Password::min(10)],
        ], [
            'name.required' => 'El nombre de la comunidad es obligatorio.',
            'name.unique' => 'Ya existe una comunidad con ese nombre.',
            'state.required' => 'El estado es obligatorio.',
            'municipality.required' => 'El municipio es obligatorio.',
            'locality.required' => 'La localidad o dirección es obligatoria.',
            'contact_phone.required' => 'El teléfono de contacto es obligatorio.',
            'contact_phone.regex' => 'El teléfono de contacto debe tener 10 dígitos.',
            ...PersonData::messages('admin.'),
            'admin.email.required' => 'El correo del administrador es obligatorio.',
            'admin.email.unique' => 'Ese correo ya está registrado.',
        ])->validate();

        $a = $data['admin'];
        $invite = empty($a['password']);
        [$community, $admin] = DB::transaction(fn () => $this->context->runAsSystem(function () use ($data, $a, $invite, $request) {
            $community = Community::create([
                'name' => trim($data['name']),
                'state' => trim($data['state']),
                'municipality' => trim($data['municipality']),
                'locality' => trim($data['locality']),
                'zone' => isset($data['zone']) && trim($data['zone']) !== '' ? trim($data['zone']) : null,
                'contact_phone' => $data['contact_phone'],
                'contact_email' => $data['contact_email'] ?? null,
            ]);
            $fullName = PersonData::fullName($a['given_names'], $a['paternal_surname'], $a['maternal_surname'] ?? null);

            if ($invite) {
                $admin = $this->invitations->invite($community, $fullName, $a['email'], Roles::ADMIN, $request->user(), [
                    'given_names' => $a['given_names'],
                    'paternal_surname' => $a['paternal_surname'],
                    'maternal_surname' => $a['maternal_surname'] ?? null,
                    'phone' => $a['phone'],
                    'address' => $a['address'],
                ]);
            } else {
                $admin = new User([
                    'given_names' => $a['given_names'],
                    'paternal_surname' => $a['paternal_surname'],
                    'maternal_surname' => $a['maternal_surname'] ?? null,
                    'phone' => $a['phone'],
                    'address' => $a['address'],
                    'email' => $a['email'],
                    'password' => $a['password'],
                ]);
                $admin->name = $fullName;
                $admin->community_id = $community->id;
                $admin->save();
                $admin->assignRole(Role::findOrCreate(Roles::ADMIN, 'web'));
                // Para que los equipos de la comunidad lo reciban en su primera sincronización.
                $this->publisher->publish($admin, 'create');
            }

            AuditLog::record('community.created', [
                'community_id' => $community->id,
                'user_id' => $request->user()->id,
                'entity' => 'communities',
                'entity_id' => $community->id,
                'data' => ['name' => $community->name, 'admin_id' => $admin->id, 'admin_name' => $admin->name, 'admin_email' => $admin->email, 'invited' => $invite],
            ]);

            return [$community, $admin];
        }));

        return ApiResponse::ok([
            ...$this->present($community),
            'admin' => UserService::present($admin->fresh()),
        ], 201);
    }

    private function present(Community $community): array
    {
        return [
            'id' => $community->id,
            'name' => $community->name,
            'state' => $community->state,
            'municipality' => $community->municipality,
            'locality' => $community->locality,
            'zone' => $community->zone,
            'contact_phone' => $community->contact_phone,
            'contact_email' => $community->contact_email,
            'is_active' => $community->is_active,
            'deactivated_at' => $community->deactivated_at?->toIso8601String(),
            'created_at' => $community->created_at?->toIso8601String(),
        ];
    }

    /** El administrador que se muestra: el activo (si hubiera varios, el primero por nombre). */
    private function presentAdmin(\Illuminate\Support\Collection $admins): ?array
    {
        $admin = $admins->first();

        return $admin === null ? null : [
            'id' => $admin->id,
            'name' => $admin->name,
            'phone' => $admin->phone,
            'email' => $admin->email,
            'is_active' => (bool) $admin->is_active,
            'status' => $admin->activated_at === null ? 'pending' : 'active',
            'others' => max(0, $admins->where('is_active', true)->count() - 1),
        ];
    }
}
