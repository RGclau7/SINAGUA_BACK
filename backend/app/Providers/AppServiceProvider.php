<?php

namespace App\Providers;

use App\Auth\AccessRules;
use App\Auth\Assignments;
use App\Auth\Jwt\JwtGuard;
use App\Auth\Jwt\JwtService;
use App\Cobradores\DbAssignments;
use App\Cobranza\CobranzaService;
use App\Cuotas\CargoDebts;
use App\Cuotas\CuotasPublisher;
use App\Entregas\EntregasService;
use App\Gastos\GastosService;
use App\Ingresos\IngresosService;
use App\Models\Cargo;
use App\Models\Entrega;
use App\Models\Gasto;
use App\Models\Ingreso;
use App\Models\Pago;
use App\Movements\MovementRegistry;
use App\Movements\MovementVoided;
use App\Padron\Debts;
use App\Padron\PadronPublisher;
use App\Tenancy\CurrentCommunity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentCommunity::class);
        $this->app->singleton(JwtService::class);
        // Viviendas asignadas a cada cobrador (M6).
        $this->app->bind(Assignments::class, DbAssignments::class);
        $this->app->singleton(MovementRegistry::class);
        // Adeudo = cargos sin anular; M7 restará los pagos.
        $this->app->bind(Debts::class, CargoDebts::class);
        $this->app->singleton(CuotasPublisher::class);
        $this->app->singleton(CobranzaService::class);
        $this->app->singleton(PadronPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Búsquedas sin acentos ni mayúsculas (whereSearch): en SQLite hace falta translate().
        \Illuminate\Database\Eloquent\Builder::macro('whereSearch', function (string $column, string $text) {
            return \App\Support\Search::where($this, $column, $text);
        });
        \Illuminate\Database\Eloquent\Builder::macro('orWhereSearch', function (string $column, string $text) {
            return \App\Support\Search::where($this, $column, $text, true);
        });
        Event::listen(\Illuminate\Database\Events\ConnectionEstablished::class,
            fn ($e) => \App\Support\Search::registerSqliteFunctions($e->connection));

        // Guard "jwt": valida el Bearer JWT y su sesión (vida máxima e inactividad de 30 min).
        Auth::viaRequest('jwt', fn (Request $request) => app(JwtGuard::class)($request));

        AccessRules::register();

        // Cargos: se anulan con /movements/cargos/{id}/void y el cambio baja a los equipos.
        $this->app->afterResolving(MovementRegistry::class, function (MovementRegistry $r) {
            $r->register(Cargo::ENTITY, Cargo::class);
            $r->register(Pago::ENTITY, Pago::class);
            $r->register(Ingreso::ENTITY, Ingreso::class);
            $r->register(Gasto::ENTITY, Gasto::class);
            $r->register(Entrega::ENTITY, Entrega::class);
        });
        Event::listen(MovementVoided::class, function (MovementVoided $event) {
            match (true) {
                $event->movement instanceof Cargo => app(CuotasPublisher::class)->publish($event->movement, 'void'),
                // Al anular un pago sus cargos vuelven a quedar por pagar (se calcula al consultar).
                $event->movement instanceof Pago => app(CobranzaService::class)->publish($event->movement, 'void'),
                // Al anular un ingreso baja el saldo de su categoría.
                $event->movement instanceof Ingreso => app(IngresosService::class)->publish($event->movement, 'void'),
                // Al anular un gasto, el dinero vuelve a su fondo.
                $event->movement instanceof Gasto => app(GastosService::class)->publish($event->movement, 'void'),
                // Al anular una entrega, sus recibos vuelven a quedar por entregar.
                $event->movement instanceof Entrega => app(EntregasService::class)->publish($event->movement, 'void'),
                default => null,
            };
        });

        // Columnas de anulación para las tablas de movimientos: $table->voidable();
        Blueprint::macro('voidable', function () {
            /** @var Blueprint $this */
            $this->timestamp('voided_at')->nullable();
            $this->uuid('voided_by')->nullable();
            $this->text('void_reason')->nullable();
        });

        // Freno general por IP para el login (el bloqueo por cuenta está en LoginService).
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        // PIN de seguridad, PIN de activación y códigos: además del bloqueo por intentos de cada uno.
        RateLimiter::for('pin', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
    }
}
