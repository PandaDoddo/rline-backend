<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

/** Material part of DF inputs; interpolate inside the selected standard, never extrapolate. */
final class MaterialStrengthInputs
{
    public function resolve(?array $material, mixed $designTemperature, bool $pipeline): array
    {
        $this->number($designTemperature);
        $column = $pipeline ? 'rd' : 'gost';
        $rows = [];
        foreach ($material['stress']['rows'] ?? [] as $row) {
            $value = $row[$column] ?? null;
            $this->number($row['t'] ?? null);
            $this->number($value);
            if (! isset($row['t']) || $value === null) {
                continue;
            }
            $rows[] = ['t' => $row['t'], 'value' => $value];
        }
        usort($rows, fn ($a, $b) => $a['t'] <=> $b['t']);
        foreach ($rows as $i => $row) {
            if ($row['value'] < 0 || $i > 0 && $row['t'] == $rows[$i - 1]['t']) {
                throw new InvalidArgumentException('Повтор температуры или отрицательное допускаемое напряжение.');
            }
        }
        $axis = array_column($rows, 't');
        $values = array_column($rows, 'value');
        $at = function ($temperature) use ($axis, $values) {
            if ($temperature === null || ! $axis || $temperature < $axis[0] || $temperature > $axis[array_key_last($axis)]) {
                return null;
            }

            return (new TabulatedInterpolation)->linear($axis, $values, $temperature);
        };
        $stress = $at($designTemperature);
        $room = $at(20);
        $this->number($material['re_nom'] ?? null);
        $this->number($material['rm_nom'] ?? null);
        if (($material['re_nom'] ?? 0) < 0 || ($material['rm_nom'] ?? 0) < 0) {
            throw new InvalidArgumentException('Номинальная прочностная характеристика не может быть отрицательной.');
        }
        $ratio = $stress !== null && $room !== null && $room > 0 ? $stress / $room : null;
        $yield = $ratio !== null && isset($material['re_nom']) ? $material['re_nom'] * $ratio : null;
        $tensile = $ratio !== null && isset($material['rm_nom']) ? $material['rm_nom'] * $ratio : null;
        foreach ([$yield, $tensile] as $value) {
            if ($value !== null && (! is_finite($value) || $value < 0)) {
                throw new InvalidArgumentException('Некорректное механическое свойство материала.');
            }
        }
        if ($yield !== null && $tensile !== null && $yield > $tensile) {
            throw new InvalidArgumentException('Предел текучести больше временного сопротивления.');
        }
        $input = ['S' => $stress, 'YS' => $yield, 'TS' => $tensile];

        return ['values' => $input, 'missing' => array_keys(array_filter($input, fn ($value) => $value === null || $value <= 0)),
            'source' => ['standard' => $pipeline ? 'РД 10-249-98' : 'ГОСТ 34233.1-2017', 'column' => $column,
                'temperature_design_c' => $designTemperature, 'stress_at_20_mpa' => $room, 'strength_ratio' => $ratio]];
    }

    private function number(mixed $value): void
    {
        if ($value !== null && ((! is_int($value) && ! is_float($value)) || ! is_finite($value))) {
            throw new InvalidArgumentException('Для прочностного входа требуется конечное число или null.');
        }
    }
}
