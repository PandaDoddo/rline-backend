<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

final class SccInspectionModel
{
    public function combine(array $counts): array
    {
        $n = [];
        foreach (['A', 'B', 'C', 'D'] as $category) {
            $value = $counts[$category] ?? 0;
            if (! is_int($value) || $value < 0 || $value > 100000) {
                throw new InvalidArgumentException('Некорректное количество инспекций SCC.');
            }
            $n[$category] = $value;
        }
        $c = $n['C'] + intdiv($n['D'], 2);
        $b = $n['B'] + intdiv($c, 2);

        return ['A' => $n['A'] + intdiv($b, 2), 'B' => $b % 2, 'C' => $c % 2, 'D' => $n['D'] % 2];
    }

    public function describe(array $counts, float $severity, array $table): array
    {
        $combined = $this->combine($counts);
        $effective = null;
        foreach (['A', 'B', 'C', 'D'] as $category) {
            if ($combined[$category] > 0) {
                $effective = $category;
                break;
            }
        }
        $number = $effective ? $combined[$effective] : 0;
        $row = null;
        foreach ($table as $candidate) {
            if (isset($candidate['svi']) && (float) $candidate['svi'] === $severity) {
                $row = $candidate;
                break;
            }
        }
        $index = ['D' => 0, 'C' => 1, 'B' => 2, 'A' => 3];
        $base = $row === null ? null : ($effective ? $row['n'][min($number, 6) - 1][$index[$effective]] : $row['e']);
        if ($base !== null && (! is_numeric($base) || ! is_finite((float) $base) || $base < 0)) {
            throw new InvalidArgumentException('Некорректная базовая таблица SCC.');
        }

        return ['Nc' => $combined, 'eff' => $effective, 'nEff' => $number, 'DfB' => $base];
    }
}
