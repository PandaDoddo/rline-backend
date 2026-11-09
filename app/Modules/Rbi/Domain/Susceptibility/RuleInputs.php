<?php

namespace App\Modules\Rbi\Domain\Susceptibility;

use InvalidArgumentException;

final class RuleInputs
{
    public static function flag(array $values, string $key): ?bool
    {
        $value = $values[$key] ?? null;
        if (is_bool($value) || $value === null) {
            return $value;
        }

        return match ($value) {
            'да' => true,'нет' => false,'','—','неизвестно' => null,default => throw new InvalidArgumentException('Некорректный логический вход: '.$key)
        };
    }

    public static function number(array $values, string $key, ?float $min = null, ?float $max = null): ?float
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || $min !== null && $value < $min || $max !== null && $value > $max) {
            throw new InvalidArgumentException('Некорректный числовой вход: '.$key);
        }

        return (float) $value;
    }

    public static function heating(array $values): array
    {
        $heat = self::flag($values, 'heat');
        $steam = self::flag($values, 'steam');
        if ($heat === true || $steam === true) {
            return [true, []];
        }
        if ($heat === false && $steam === false) {
            return [false, []];
        }

        return [null, array_values(array_filter(['heat', 'steam'], fn ($key) => self::flag($values, $key) === null))];
    }

    public static function range(array $rows, float $value): ?array
    {
        foreach ($rows as $row) {
            if (($row['lo'] === null || $value >= $row['lo']) && ($row['hi'] === null || $value < $row['hi'])) {
                return $row;
            }
        }

        return null;
    }

    public static function interpolate(array $points, float $value): ?float
    {
        if (! $points) {
            return null;
        }
        if ($value <= $points[0][0]) {
            return $points[0][1];
        }
        $last = $points[array_key_last($points)];
        if ($value >= $last[0]) {
            return $last[1];
        }
        for ($index = 1; $index < count($points); $index++) {
            if ($value <= $points[$index][0]) {
                [$a,$b] = [$points[$index - 1], $points[$index]];
                if ($b[0] <= $a[0]) {
                    throw new InvalidArgumentException('Таблица интерполяции должна возрастать.');
                }

                return $a[1] + ($b[1] - $a[1]) * ($value - $a[0]) / ($b[0] - $a[0]);
            }
        }

        return null;
    }
}
