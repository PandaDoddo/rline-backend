<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

final class DamageFactorAggregator
{
    public const MECHANISMS = ['thin', 'causticscc', 'aminescc', 'ssc', 'hich2s', 'acscc', 'pascc', 'clscc', 'hschf', 'hichf', 'ecl', 'ccl', 'cui', 'ext', 'bf', 'lase', 'e885', 'sigma', 'htha', 'mfat'];

    private const SCC = ['causticscc', 'aminescc', 'ssc', 'hich2s', 'acscc', 'pascc', 'clscc', 'hschf', 'hichf'];

    /** Missing active factors remain missing; only explicitly inactive mechanisms contribute zero. */
    public function calculate(array $factors, array $active, ?string $internalType, ?string $externalType): array
    {
        if (array_diff([...array_keys($factors), ...$active], self::MECHANISMS)) {
            throw new InvalidArgumentException('Неизвестный механизм повреждения.');
        }
        foreach ([$internalType, $externalType] as $type) {
            if ($type !== null && ! in_array($type, ['общая', 'локальная'], true)) {
                throw new InvalidArgumentException('Неизвестный тип коррозии.');
            }
        }
        $missing = [];
        $values = array_fill_keys(self::MECHANISMS, 0.0);
        foreach ($active as $key) {
            $value = $factors[$key] ?? null;
            if ($value === null) {
                $missing[] = 'df.'.$key;
                $values[$key] = null;

                continue;
            }
            if ((! is_int($value) && ! is_float($value)) || ! is_finite($value) || $value < 0) {
                throw new InvalidArgumentException('Фактор повреждения должен быть конечным неотрицательным числом.');
            }
            $values[$key] = (float) $value;
        }
        if ($internalType === null) {
            $missing[] = 'internal_corrosion_type';
        }
        if ($externalType === null) {
            $missing[] = 'external_corrosion_type';
        }
        $maximum = function (array $keys) use ($values): ?float {
            $members = array_intersect_key($values, array_flip($keys));

            return in_array(null, $members, true) ? null : max($members);
        };
        $brittle = $maximum(['bf', 'lase', 'e885', 'sigma']) === null ? null : max($values['bf'] + $values['lase'], $values['e885'], $values['sigma']);
        $groups = ['thin' => $values['thin'], 'scc' => $maximum(self::SCC),
            'extd' => $maximum(['ext', 'cui', 'ecl', 'ccl']),
            'brit' => $brittle, 'htha' => $values['htha'], 'mfat' => $values['mfat']];
        $local = $internalType === 'локальная' && $externalType === 'локальная';
        $total = $missing === [] ? ($local ? max($groups['thin'], $groups['extd']) : $groups['thin'] + $groups['extd']) + $groups['scc'] + $groups['brit'] + $groups['htha'] + $groups['mfat'] : null;
        if ($total !== null && ! is_finite($total)) {
            throw new InvalidArgumentException('Переполнение при агрегировании факторов повреждения.');
        }

        // Partial group values are diagnostics, never a complete Df-total.
        return ['ready' => $missing === [], 'missing' => $missing, 'values' => $values, 'groups' => $groups, 'local' => $local, 'total' => $missing === [] ? $total : null];
    }
}
