<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

final class OperatingFractions
{
    public function validate(array $flows): array
    {
        if (! $flows) {
            return ['flows'];
        }
        if (! array_is_list($flows)) {
            throw new InvalidArgumentException('Потоки расчёта должны быть списком.');
        }
        $ids = [];
        $sum = 0.0;
        $missing = [];
        foreach ($flows as $flow) {
            $id = $flow['fid'] ?? null;
            if (! is_string($id) || $id === '' || isset($ids[$id]) || ! isset($flow['act']) || ! is_bool($flow['act'])) {
                throw new InvalidArgumentException('Нужны уникальный ID и явная активность механизма на потоке.');
            }
            $ids[$id] = true;
            if (! isset($flow['w'])) {
                if ($flow['act']) {
                    $missing[] = 'flows.'.$id.'.w';
                }

                continue;
            }
            if (! is_numeric($flow['w']) || ! is_finite((float) $flow['w']) || $flow['w'] < 0 || $flow['w'] > 100) {
                throw new InvalidArgumentException('Доля времени потока должна быть от 0 до 100 %.');
            }
            $sum += $flow['w'];
        }
        if ($sum > 100 + 1e-8) {
            throw new InvalidArgumentException('Сумма долей времени потоков превышает 100 %.');
        }

        return $missing;
    }

    public function sum(array $flows): ?float
    {
        $result = 0.0;
        foreach ($flows as $flow) {
            if (! $flow['act']) {
                continue;
            }
            if (! isset($flow['w'],$flow['DF'])) {
                return null;
            }
            $result += $flow['w'] / 100 * $flow['DF'];
        }
        if (! is_finite($result)) {
            throw new InvalidArgumentException('Переполнение взвешенного фактора повреждения.');
        }

        return $result;
    }
}
