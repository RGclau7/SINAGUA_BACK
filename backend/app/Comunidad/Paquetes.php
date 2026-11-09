<?php

namespace App\Comunidad;

use App\Models\Community;
use Illuminate\Validation\ValidationException;

/**
 * Paquetes de la comunidad: nombre, cuántas familias caben en la casa y cuota al mes. Toda casa
 * tiene un paquete y paga la cuota de su paquete. Vienen tres de fábrica (sin precio hasta que la
 * comunidad se lo ponga) y cada comunidad los cambia.
 */
final class Paquetes
{
    public const MAX_FAMILIAS = 10;

    public static function defaults(): array
    {
        return [
            ['id' => 'p1', 'name' => 'Paquete 1', 'familias' => 1, 'cuota_cents' => null, 'activo' => true],
            ['id' => 'p2', 'name' => 'Paquete 2', 'familias' => 2, 'cuota_cents' => null, 'activo' => true],
            ['id' => 'p3', 'name' => 'Paquete 3', 'familias' => 3, 'cuota_cents' => null, 'activo' => true],
        ];
    }

    /** Paquetes de la comunidad (los de fábrica si todavía no define ninguno). */
    public static function of(Community $community): array
    {
        $raw = $community->paquetes;
        $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($list) && $list !== [] ? array_values($list) : self::defaults();
    }

    public static function find(Community $community, ?string $id): ?array
    {
        foreach (self::of($community) as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }

        return null;
    }

    /** Paquete que se propone al registrar una casa: el activo de menos familias. */
    public static function defaultId(Community $community): ?string
    {
        $active = array_values(array_filter(self::of($community), fn ($p) => $p['activo'] ?? true));
        usort($active, fn ($a, $b) => $a['familias'] <=> $b['familias']);

        return $active[0]['id'] ?? null;
    }

    /**
     * Valida y deja la lista limpia. Los paquetes no se borran (las casas guardan su id):
     * se desactivan.
     *
     * @throws ValidationException
     */
    public static function normalize(array $list, bool $requirePrice = true): array
    {
        $out = [];
        $ids = [];
        foreach ($list as $p) {
            $name = trim(preg_replace('/\s+/', ' ', (string) ($p['name'] ?? '')));
            $familias = (int) ($p['familias'] ?? 0);
            $cuota = $p['cuota_cents'] ?? null;
            $id = (string) ($p['id'] ?? '');
            $errors = [];
            if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
                $errors[] = 'Escriba el nombre del paquete.';
            }
            if ($familias < 1 || $familias > self::MAX_FAMILIAS) {
                $errors[] = 'Las familias del paquete van de 1 a '.self::MAX_FAMILIAS.'.';
            }
            if ($cuota !== null && (! is_numeric($cuota) || (int) $cuota < 1 || (int) $cuota > 10_000_000)) {
                $errors[] = 'La cuota del paquete debe ser mayor a cero.';
            }
            if ($requirePrice && $cuota === null && ($p['activo'] ?? true)) {
                $errors[] = "Ponga la cuota al mes de «{$name}».";
            }
            if ($id === '' || mb_strlen($id) > 40 || isset($ids[$id])) {
                $errors[] = 'Paquete inválido.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['paquetes' => $errors]);
            }
            $ids[$id] = true;
            $out[] = ['id' => $id, 'name' => $name, 'familias' => $familias, 'cuota_cents' => $cuota === null ? null : (int) $cuota, 'activo' => (bool) ($p['activo'] ?? true)];
        }
        if ($out === [] || count(array_filter($out, fn ($p) => $p['activo'])) === 0) {
            throw ValidationException::withMessages(['paquetes' => ['Debe haber al menos un paquete activo.']]);
        }

        return $out;
    }
}
