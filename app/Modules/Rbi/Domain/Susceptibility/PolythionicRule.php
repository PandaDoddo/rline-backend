<?php

namespace App\Modules\Rbi\Domain\Susceptibility;

final class PolythionicRule implements SusceptibilityRule
{
    public function evaluate(array $component, array $flow, array $tables): SusceptibilityResult
    {
        $operating = RuleInputs::flag($flow, 'psOp');
        $shutdown = RuleInputs::flag($flow, 'psSd');
        if ($operating === false && $shutdown === false) {
            return new SusceptibilityResult('нет', 'нет воздействия S, O₂ и H₂O');
        }
        if ($operating !== true && $shutdown !== true) {
            return SusceptibilityResult::blocked(['psOp', 'psSd'], 'не задано воздействие S, O₂, H₂O при работе / останове');
        }
        $temperature = RuleInputs::number($component, 'T', -273.15);
        if ($temperature === null) {
            return SusceptibilityResult::blocked(['T']);
        }
        $group = null;
        foreach ($tables['c82'] ?? [] as $row) {
            if ($row['g'] === ($component['alloy'] ?? null)) {
                $group = $row;
                break;
            }
        }
        $history = array_search($component['thist'] ?? null, $tables['th'] ?? [], true);
        if ($group === null) {
            return SusceptibilityResult::blocked(['alloy'], 'Группа сплава не выбрана; первая строка не подставляется.');
        }
        if ($history === false) {
            return SusceptibilityResult::blocked(['thist']);
        }
        $state = $group[$temperature < 427 ? 'lo' : 'hi'][$history] ?? null;
        if ($state === null) {
            return SusceptibilityResult::blocked(['polythionic_table']);
        }
        $protection = RuleInputs::flag($component, 'prot');
        if ($state !== 'нет' && $protection === null) {
            return SusceptibilityResult::blocked(['prot']);
        }
        $why = 'сплав «'.$group['g'].'», '.$tables['th'][$history].', T '.($temperature < 427 ? '< 427' : '≥ 427').' °C → '.$state;
        if ($protection) {
            $levels = ['нет', 'низкая', 'средняя', 'высокая'];
            $index = array_search($state, $levels, true);
            $state = $levels[max(0, $index - 1)];
            $why .= '; защита при простое (РП7) → '.$state;
        }

        return new SusceptibilityResult($state, $why);
    }
}
