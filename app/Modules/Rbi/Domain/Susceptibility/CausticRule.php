<?php

namespace App\Modules\Rbi\Domain\Susceptibility;

final class CausticRule implements SusceptibilityRule
{
    public function evaluate(array $component, array $flow, array $tables): SusceptibilityResult
    {
        $pwht = RuleInputs::flag($component, 'pwht');
        $temperature = RuleInputs::number($component, 'T', -273.15);
        $naoh = RuleInputs::number($flow, 'naoh', 0, 100);
        if ($pwht === true && ($temperature === null || $naoh === null)) {
            return new SusceptibilityResult('нет', 'PWHT → нет');
        }
        $missing = [];
        if ($temperature === null) {
            $missing[] = 'T';
        } if ($naoh === null) {
            $missing[] = 'naoh';
        } if ($missing) {
            return SusceptibilityResult::blocked($missing);
        }
        $x = min($naoh, 50);
        $upper = RuleInputs::interpolate($tables['l1'] ?? [], $x);
        $lower = RuleInputs::interpolate($tables['l2'] ?? [], $x);
        if ($upper === null || $lower === null) {
            return SusceptibilityResult::blocked(['caustic_lines']);
        }
        $area = $temperature < $lower ? 'A' : ($temperature < $upper ? 'B' : 'C');
        $why = 'область '.$area.' (NaOH '.$naoh.' %, T '.$temperature.' °C; линии '.number_format($lower, 1, '.', '').' / '.number_format($upper, 1, '.', '').' °C)';
        if ($pwht === true) {
            return new SusceptibilityResult('нет', 'PWHT → нет; '.$why, details: ['area' => $area]);
        }
        if ($pwht === null) {
            return SusceptibilityResult::blocked(['pwht']);
        }
        if ($area === 'C') {
            return new SusceptibilityResult('высокая', $why, details: ['area' => $area]);
        }
        if ($area === 'B') {
            return new SusceptibilityResult($naoh < 5 ? 'средняя' : 'высокая', $why, details: ['area' => $area]);
        }
        if ($naoh < 5) {
            [$heat,$missing] = RuleInputs::heating($component);
            if ($missing) {
                return SusceptibilityResult::blocked($missing);
            }

            return new SusceptibilityResult($heat ? 'средняя' : 'нет', $why.($heat ? ', обогрев / пропарка' : ''), details: ['area' => $area]);
        }
        $heat = RuleInputs::flag($component, 'heat');
        if ($heat === null) {
            return SusceptibilityResult::blocked(['heat']);
        }
        if ($heat) {
            return new SusceptibilityResult('высокая', $why.', обогрев', details: ['area' => $area]);
        }
        $steam = RuleInputs::flag($component, 'steam');
        if ($steam === null) {
            return SusceptibilityResult::blocked(['steam']);
        }

        return new SusceptibilityResult($steam ? 'низкая' : 'нет', $why.($steam ? ', пропарка' : ''), details: ['area' => $area]);
    }
}
