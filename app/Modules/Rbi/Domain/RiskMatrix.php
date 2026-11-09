<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

final class RiskMatrix
{
    public const VERSION = 'risk-matrix-old-v1';

    public const PROBABILITY_THRESHOLDS = [3.06e-5, 3.06e-4, 3.06e-3, 3.06e-2];

    public const ZONES = [['L', 'L', 'M', 'M', 'MH'], ['L', 'L', 'M', 'MH', 'MH'], ['L', 'M', 'M', 'MH', 'H'], ['L', 'M', 'MH', 'H', 'H'], ['M', 'MH', 'MH', 'H', 'H']];

    public function probabilityCategory(?float $pof, array $thresholds = self::PROBABILITY_THRESHOLDS): ?int
    {
        if (count($thresholds) !== 4) {
            throw new InvalidArgumentException('Нужны четыре границы вероятности.');
        }
        $previous = -1.0;
        foreach ($thresholds as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite($value) || $value < 0 || $value <= $previous) {
                throw new InvalidArgumentException('Границы вероятности должны строго возрастать.');
            }
            $previous = $value;
        }
        if ($pof === null) {
            return null;
        }
        if (! is_finite($pof) || $pof < 0) {
            throw new InvalidArgumentException('Вероятность отказа должна быть конечной и неотрицательной.');
        }
        foreach (array_values($thresholds) as $index => $value) {
            if ($pof <= $value) {
                return $index + 1;
            }
        }

        return 5;
    }

    public function zone(?int $probability, ?string $consequence, array $zones = self::ZONES): ?string
    {
        if ($probability === null || $consequence === null) {
            return null;
        }
        if ($probability < 1 || $probability > 5 || ! in_array($consequence, ['A', 'B', 'C', 'D', 'E'], true)) {
            throw new InvalidArgumentException('Категории матрицы риска вне диапазона.');
        }
        if (count($zones) !== 5) {
            throw new InvalidArgumentException('Матрица риска должна иметь размер 5×5.');
        }
        foreach ($zones as $row) {
            if (count($row) !== 5 || array_diff($row, ['L', 'M', 'MH', 'H'])) {
                throw new InvalidArgumentException('Некорректная зона в матрице риска.');
            }
        }

        return $zones[$probability - 1][ord($consequence) - ord('A')];
    }
}
