<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

/** Shared three-state inspection reliability model, with stable Bayesian posteriors. */
final class InspectionReliability
{
    public function calculate(float $art, float $strengthRatio, array $input): array
    {
        if (! is_finite($art) || ! is_finite($strengthRatio) || $art < 0 || $strengthRatio < 0) {
            throw new InvalidArgumentException('Некорректные параметры надёжности.');
        }
        $priors = $this->probabilities($input['prp']);
        if (array_sum($priors) <= 0) {
            throw new InvalidArgumentException('Предварительные вероятности не могут быть все нулевыми.');
        }
        $likelihoods = [];
        foreach (['A', 'B', 'C', 'D'] as $category) {
            if (! isset($input['cop'][$category])) {
                throw new InvalidArgumentException('Отсутствует категория таблицы условных вероятностей.');
            }
            $likelihoods[$category] = $this->probabilities($input['cop'][$category]);
            $count = $input['N'][$category] ?? 0;
            if (! is_int($count) || $count < 0 || $count > 100000) {
                throw new InvalidArgumentException('Некорректное количество инспекций.');
            }
        }
        $r = ['Art' => $art, 'SRP' => $strengthRatio];
        $r['Cop'] = [];
        $r['I'] = [];
        $logWeights = [];
        for ($index = 0; $index < 3; $index++) {
            $cop = 1.0;
            $logWeight = $priors[$index] > 0 ? log($priors[$index]) : -INF;
            foreach (['A', 'B', 'C', 'D'] as $category) {
                $count = $input['N'][$category] ?? 0;
                $probability = $likelihoods[$category][$index];
                $cop *= pow($probability, $count);
                if ($count > 0) {
                    $logWeight += $probability > 0 ? $count * log($probability) : -INF;
                }
            }
            $r['Cop'][] = $cop;
            $r['I'][] = $priors[$index] * $cop;
            $logWeights[] = $logWeight;
        }
        $largest = max($logWeights);
        if (! is_finite($largest)) {
            throw new InvalidArgumentException('Наблюдение невозможно для заданной таблицы вероятностей.');
        }
        // Log-sum-exp preserves Bayes for many inspections instead of guessing 1/3 after underflow.
        $weights = array_map(fn ($value) => exp($value - $largest), $logWeights);
        $sum = array_sum($weights);
        $r['Pop'] = array_map(fn ($value) => $value / $sum, $weights);
        $r['beta'] = [];
        foreach ([1, 2, 4] as $damageState) {
            $a = $r['Art'];
            $s = $r['SRP'];
            $r['beta'][] = (1 - $damageState * $a - $s) / sqrt($damageState * $damageState * $a * $a * 0.2 * 0.2 + pow(1 - $damageState * $a, 2) * 0.2 * 0.2 + $s * $s * 0.05 * 0.05);
        }
        $r['Dfb'] = 0.0;
        for ($index = 0; $index < 3; $index++) {
            $r['Dfb'] += $r['Pop'][$index] * $this->normalCdf(-$r['beta'][$index]) / 1.56e-4;
        }
        unset($r['Art'], $r['SRP']);

        return $r;
    }

    private function probabilities(mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values) || count($values) !== 3) {
            throw new InvalidArgumentException('Требуются три вероятности состояния.');
        }
        foreach ($values as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite($value) || $value < 0 || $value > 1) {
                throw new InvalidArgumentException('Вероятность должна быть от 0 до 1.');
            }
        }

        return $values;
    }

    private function normalCdf(float $z): float
    {
        $x = -$z / sqrt(2);
        $absolute = abs($x);
        $t = 1 / (1 + 0.5 * $absolute);
        $polynomial = 0.17087277;
        foreach ([-0.82215223, 1.48851587, -1.13520398, 0.27886807, -0.18628806, 0.09678418, 0.37409196, 1.00002368] as $coefficient) {
            $polynomial = $coefficient + $t * $polynomial;
        }
        $r = $t * exp(-$absolute * $absolute - 1.26551223 + $t * $polynomial);

        return 0.5 * ($x >= 0 ? $r : 2 - $r);
    }
}
