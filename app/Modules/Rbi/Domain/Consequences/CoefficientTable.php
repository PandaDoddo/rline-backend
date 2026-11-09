<?php

namespace App\Modules\Rbi\Domain\Consequences;

use InvalidArgumentException;

/** Parsing is confined to immutable reference tables, never used to coerce unknown user inputs. */
final class CoefficientTable
{
    public static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === 'N') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace([',', '−', ' '], ['.', '-', ''], $value);
        }
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException('Некорректный коэффициент таблицы последствий.');
        }

        return (float) $value;
    }

    public static function pair(array $table, int $row, string $phase): array
    {
        $offset = $phase === 'газ' ? 0 : 2;

        return [self::number($table[$row][$offset] ?? null), self::number($table[$row][$offset + 1] ?? null)];
    }
}
