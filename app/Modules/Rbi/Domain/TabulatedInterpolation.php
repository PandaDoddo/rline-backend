<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

final class TabulatedInterpolation
{
    public function linear(array $axis, array $values, float $point): ?float
    {
        if (! $axis || count($axis) !== count($values)) {
            return null;
        }
        $last = count($axis) - 1;
        $ascending = $axis[$last] > $axis[0];
        foreach ($axis as $index => $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || ! is_numeric($values[$index]) || ! is_finite((float) $values[$index])) {
                throw new InvalidArgumentException('Некорректная таблица интерполяции.');
            }if ($index > 0 && ($ascending ? $value <= $axis[$index - 1] : $value >= $axis[$index - 1])) {
                throw new InvalidArgumentException('Ось таблицы должна быть строго монотонной.');
            }
        }
        if ($ascending ? $point <= $axis[0] : $point >= $axis[0]) {
            return (float) $values[0];
        }
        if ($ascending ? $point >= $axis[$last] : $point <= $axis[$last]) {
            return (float) $values[$last];
        }
        for ($index = 1; $index <= $last; $index++) {
            if ($ascending ? $point <= $axis[$index] : $point >= $axis[$index]) {
                return $values[$index - 1] + ($values[$index] - $values[$index - 1]) * ($point - $axis[$index - 1]) / ($axis[$index] - $axis[$index - 1]);
            }
        }

        return null;
    }

    public function bilinear(array $columns, array $rows, array $values, float $x, float $y): ?float
    {
        if (count($values) !== count($rows)) {
            return null;
        }
        $column = [];
        foreach ($values as $row) {
            $value = $this->linear($columns, $row, $x);
            if ($value === null) {
                return null;
            }$column[] = $value;
        }

        return $this->linear($rows, $column, $y);
    }
}
