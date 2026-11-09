<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Búsqueda sin acentos ni mayúsculas, igual que en el escritorio: "gonzalez", "GONZALEZ" y
 * "González" encuentran "González". La ñ se queda distinta: "Peña" no es "Pena".
 */
final class Search
{
    private const FROM = 'ÁÉÍÓÚÜÀÈÌÒÙÑáéíóúüàèìòù';

    private const TO = 'AEIOUUAEIOUñaeiouuaeiou';

    /** Lo que se escribió, en minúsculas y sin acentos (la ñ se conserva). */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');

        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    }

    /** Patrón LIKE de lo que se escribió. */
    public static function pattern(string $text): string
    {
        return '%'.addcslashes(self::normalize($text), '%_\\').'%';
    }

    /** Agrega "columna contiene el texto" (o con $or, "o la columna contiene…"). */
    public static function where(Builder $query, string $column, string $text, bool $or = false): Builder
    {
        $grammar = $query->getQuery()->getGrammar();
        $sql = 'lower(translate('.$grammar->wrap($column).", '".self::FROM."', '".self::TO."')) like ?";

        return $or ? $query->orWhereRaw($sql, [self::pattern($text)]) : $query->whereRaw($sql, [self::pattern($text)]);
    }

    /** SQLite (pruebas) no trae translate(): se agrega con la misma regla. */
    public static function registerSqliteFunctions(\Illuminate\Database\Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }
        $pdo = $connection->getPdo();
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('translate', function (?string $value, string $from, string $to): ?string {
                if ($value === null) {
                    return null;
                }
                $map = array_combine(mb_str_split($from), mb_str_split($to));

                return strtr($value, $map);
            }, 3);
        }
    }
}
