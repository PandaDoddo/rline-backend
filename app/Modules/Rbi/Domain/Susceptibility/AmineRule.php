<?php

namespace App\Modules\Rbi\Domain\Susceptibility;

final class AmineRule implements SusceptibilityRule
{
    public function evaluate(array $component, array $flow, array $tables): SusceptibilityResult
    {
        $pwht = RuleInputs::flag($component, 'pwht');
        if ($pwht === true) {
            return new SusceptibilityResult('нет', 'PWHT → нет');
        }
        $rich = RuleInputs::flag($flow, 'amRich');
        if ($rich === false) {
            return new SusceptibilityResult('нет', 'нет контакта с амином, содержащим H₂S/CO₂');
        }
        if ($rich === null) {
            return SusceptibilityResult::blocked(['amRich'], 'не задано «амин с H₂S/CO₂»');
        }
        if ($pwht === null) {
            return SusceptibilityResult::blocked(['pwht']);
        }
        $temperature = RuleInputs::number($component, 'T', -273.15);
        if ($temperature === null) {
            return SusceptibilityResult::blocked(['T']);
        }
        $md = RuleInputs::flag($flow, 'amMD');
        if ($md === null) {
            return SusceptibilityResult::blocked(['amMD']);
        }
        $dea = $md ? false : RuleInputs::flag($flow, 'amDEA');
        if ($dea === null) {
            return SusceptibilityResult::blocked(['amDEA']);
        }
        $heating = false;
        if ($temperature <= 82.22 && ! ($md && $temperature >= 37.78) && ! ($dea && $temperature >= 60)) {
            [$heating,$missing] = RuleInputs::heating($component);
            if ($missing) {
                return SusceptibilityResult::blocked($missing);
            }
        }
        if ($md) {
            $state = $temperature > 82.22 ? 'высокая' : ($temperature >= 37.78 ? 'средняя' : ($heating ? 'средняя' : 'низкая'));
            $label = 'MEA / DIPA';
        } elseif ($dea) {
            $state = $temperature > 82.22 ? 'средняя' : ($temperature >= 60 ? 'низкая' : ($heating ? 'низкая' : 'нет'));
            $label = 'DEA';
        } else {
            $state = $temperature > 82.22 ? 'низкая' : ($heating ? 'низкая' : 'нет');
            $label = 'прочие амины (MDEA и др.)';
        }

        return new SusceptibilityResult($state, $label.', T '.$temperature.' °C'.($heating ? ', обогрев / пропарка' : ''));
    }
}
