<?php

namespace App\Modules\Rbi\Domain\Consequences;

use InvalidArgumentException;

final class ToxicConsequences
{
    public const SUBSTANCES = ['HF', 'H2S', 'NH3', 'Cl2', 'AlCl3', 'CO', 'HCl', 'HNO3', 'NO2', 'COCl2', 'TDI', 'EE', 'EO', 'PO'];

    public function calculate(array $input, array $release, array $tables): array
    {
        $maximum = [0, 0, 0, 0];
        $bySubstance = [];
        foreach ($input['tox'] as $substance => $fraction) {
            if ($fraction <= 0) {
                continue;
            }
            $values = [];
            foreach ($release['diameters'] as $index => $diameter) {
                if (! $diameter) {
                    $values[] = 0;

                    continue;
                }
                $instant = $release['types'][$index] === 'мгновенный';
                $duration = $release['toxic_duration'][$index] / 60;
                $quantity = ($instant ? $release['mass'][$index] : $release['rate'][$index]) * $fraction;
                if (in_array($substance, ['HF', 'H2S', 'NH3', 'Cl2'], true)) {
                    if ($release['phase'] !== 'газ') {
                        $values[] = 0;

                        continue;
                    }
                    $table = $tables[in_array($substance, ['HF', 'H2S'], true) ? 'cofToxic411' : 'cofToxic412'];
                    $row = $instant ? $this->instant($table) : $this->duration($table, $duration);
                    $offset = in_array($substance, ['HF', 'NH3'], true) ? 1 : 3;
                    $a = CoefficientTable::number($row[$offset]);
                    $b = CoefficientTable::number($row[$offset + 1]);
                    $value = $quantity > 0 ? (in_array($substance, ['HF', 'H2S'], true) ? 0.0929 * pow(10, $a * log10(2.205 * $quantity) + $b) : $a * pow($quantity, $b)) : 0;
                } else {
                    $position = array_search($substance, array_slice(self::SUBSTANCES, 4), true);
                    $rows = $tables['cofToxic413'][$position][2];
                    $row = count($rows) === 1 && $rows[0][0] === 'A' ? $rows[0] : ($instant ? $rows[$rows[0][0] === '1.5' ? 1 : 0] : $this->duration($rows, $duration));
                    $offset = $release['phase'] === 'газ' ? 1 : 3;
                    $a = CoefficientTable::number($row[$offset]);
                    $b = CoefficientTable::number($row[$offset + 1]);
                    $value = $quantity > 0 && $a !== null && $b !== null ? $a * pow($quantity, $b) : 0;
                }
                $values[] = $value;
                $maximum[$index] = max($maximum[$index], $value);
            }
            $bySubstance[$substance] = $values;
        }

        return ['maximum' => $maximum, 'substances' => $bySubstance];
    }

    private function instant(array $rows): array
    {
        foreach ($rows as $row) {
            if ($row[0] === 'I') {
                return $row;
            }
        }
        throw new InvalidArgumentException('Нет коэффициентов мгновенного токсичного выброса.');
    }

    private function duration(array $rows, float $duration): array
    {
        $last = null;
        foreach ($rows as $row) {
            if (in_array($row[0], ['I', 'A'], true)) {
                continue;
            }$last = $row;
            if ((float) $row[0] >= $duration - 1e-9) {
                return $row;
            }
        }

        return $last ?? throw new InvalidArgumentException('Нет коэффициентов длительности токсичного выброса.');
    }
}
