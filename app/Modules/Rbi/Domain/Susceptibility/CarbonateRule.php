<?php

namespace App\Modules\Rbi\Domain\Susceptibility;

final class CarbonateRule implements SusceptibilityRule
{
    public function evaluate(array $component, array $flow, array $tables): SusceptibilityResult
    {
        $pwht = RuleInputs::flag($component, 'pwht');
        if ($pwht === true) {
            return new SusceptibilityResult('нет', 'эффективная PWHT → нет');
        }
        $ph = RuleInputs::number($flow, 'ph', 0, 14);
        $carbonate = RuleInputs::number($flow, 'co3', 0);
        $missing = [];
        if ($ph === null) {
            $missing[] = 'ph';
        } if ($carbonate === null) {
            $missing[] = 'co3';
        }
        if ($missing) {
            return SusceptibilityResult::blocked($missing, 'нет pH / CO₃²⁻');
        }
        $row = RuleInputs::range($tables['c22'] ?? [], $ph);
        $column = $carbonate < 10 ? 0 : ($carbonate < 100 ? 1 : ($carbonate < 1000 ? 2 : 3));
        $state = $row['aw'][$column] ?? null;
        if ($state === null) {
            return SusceptibilityResult::blocked(['carbonate_susceptibility_table']);
        }
        if ($state !== 'нет' && $pwht === null) {
            return SusceptibilityResult::blocked(['pwht']);
        }

        return new SusceptibilityResult($state, 'pH '.$ph.', CO₃²⁻ '.$carbonate.' ppm, '.($pwht === null ? 'PWHT не задана; при этих условиях восприимчивость отсутствует' : 'без PWHT').' (табл. 2.C.2.2)');
    }
}
