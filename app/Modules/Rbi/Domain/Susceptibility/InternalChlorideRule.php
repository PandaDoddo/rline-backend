<?php

namespace App\Modules\Rbi\Domain\Susceptibility;

use InvalidArgumentException;

final class InternalChlorideRule implements SusceptibilityRule
{
    private const LEVELS = ['нет', 'низкая', 'средняя', 'высокая'];

    public function evaluate(array $component, array $flow, array $tables): SusceptibilityResult
    {
        $ph = RuleInputs::number($flow, 'ph', 0, 14);
        if ($ph === null) {
            return SusceptibilityResult::blocked(['ph'], 'нет pH');
        }
        $temperature = RuleInputs::number($component, 'T', -273.15);
        $base = 'нет';
        if ($ph >= 2.5 && $ph < 11) {
            if ($temperature === null) {
                return SusceptibilityResult::blocked(['T']);
            }
            $row = RuleInputs::range($tables['c52'] ?? [], $temperature);
            $index = 0;
            foreach ($tables['c52ph'] ?? [] as $column => $boundary) {
                if ($ph >= $boundary) {
                    $index = $column;
                }
            }
            $base = $row['v'][$index] ?? null;
            if ($base === null) {
                return SusceptibilityResult::blocked(['chloride_susceptibility_table']);
            }
        }
        $chlorides = RuleInputs::flag($flow, 'clOn') === false ? 0 : RuleInputs::number($flow, 'cl', 0);
        $oxygen = RuleInputs::number($flow, 'o2w', 0);
        $deposits = RuleInputs::flag($flow, 'dep');
        $missing = [];
        if ($base !== 'нет') {
            if ($chlorides === null) {
                $missing[] = 'cl';
            }if ($oxygen === null) {
                $missing[] = 'o2w';
            }if ($deposits === null) {
                $missing[] = 'dep';
            }
        }
        if ($missing) {
            return SusceptibilityResult::blocked($missing);
        }
        $modifiers = [];
        foreach ([['cl10', $chlorides !== null && $chlorides < 10], ['cl100', $chlorides !== null && $chlorides > 100], ['o90', $oxygen !== null && $oxygen < 90], ['dep', $deposits === true]] as [$key,$enabled]) {
            if ($enabled) {
                $value = $tables['c53'][$key] ?? null;
                if (! is_int($value)) {
                    throw new InvalidArgumentException('Модификатор восприимчивости должен быть целым.');
                }$modifiers[$key] = $value;
            }
        }
        $sum = array_sum($modifiers);
        $index = array_search($base, self::LEVELS, true);
        if ($index === false) {
            return SusceptibilityResult::blocked(['susceptibility_level']);
        }
        $state = $index === 0 ? 'нет' : self::LEVELS[max(0, min(3, $index + $sum))];

        return new SusceptibilityResult($state, 'базовая '.$base.' (pH '.$ph.', T '.($temperature ?? '—').' °C); сумма модификаторов '.$sum, details: ['base' => $base, 'modifiers' => $modifiers]);
    }
}
