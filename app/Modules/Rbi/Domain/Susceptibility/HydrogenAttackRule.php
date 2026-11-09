<?php

namespace App\Modules\Rbi\Domain\Susceptibility;

final class HydrogenAttackRule implements SusceptibilityRule
{
    public function evaluate(array $component, array $flow, array $tables): SusceptibilityResult
    {
        $damage = RuleInputs::flag($component, 'hDmg');
        if ($damage === null) {
            return SusceptibilityResult::blocked(['hDmg']);
        }
        if ($damage) {
            $replaced = RuleInputs::flag($component, 'hRepl');
            if ($replaced === null) {
                return SusceptibilityResult::blocked(['hRepl']);
            }

            return new SusceptibilityResult($replaced ? 'высокая' : 'повреждение присутствует', $replaced ? 'повреждение наблюдалось, компонент заменён без смены материала → высокая' : 'повреждение HTHA наблюдалось, компонент не заменён');
        }
        $temperature = RuleInputs::number($component, 'T', -273.15);
        $pressure = RuleInputs::number($flow, 'pH2', 0);
        $missing = [];
        if ($temperature === null) {
            $missing[] = 'T';
        }if ($pressure === null) {
            $missing[] = 'pH2';
        }if ($missing) {
            return SusceptibilityResult::blocked($missing);
        }
        if ($temperature <= 177 || $pressure <= 0.345) {
            return new SusceptibilityResult('нет', 'T ≤ 177 °C или pH₂ ≤ 0,345 МПа');
        }
        $group = $component['grp'] ?? null;
        if ($group === null) {
            return SusceptibilityResult::blocked(['grp']);
        }
        $pwht = RuleInputs::flag($component, 'pwht');
        if ($group === 'углеродистая' && $pwht === null) {
            return SusceptibilityResult::blocked(['pwht']);
        }
        if ($group === 'C-0.5Mo' || $group === 'углеродистая' && ! $pwht) {
            return new SusceptibilityResult('высокая', $group.': T > 177 °C и pH₂ > 0,345 МПа → высокая');
        }
        $column = $group === 'углеродистая' ? 'углеродистая с PWHT' : $group;
        $index = array_search($column, $tables['cols'] ?? [], true);
        if ($index === false) {
            return SusceptibilityResult::blocked(['grp'], 'Группа не найдена в кривых API 941.');
        }
        $points = [];
        foreach ($tables['a941'] ?? [] as $row) {
            if (isset($row[$index + 1])) {
                $points[] = [$row[0], $row[$index + 1]];
            }
        }
        $limit = RuleInputs::interpolate($points, $pressure);
        if ($limit === null) {
            return SusceptibilityResult::blocked(['hydrogen_curve']);
        }
        $delta = $temperature - $limit;
        $state = $delta >= 0 ? 'высокая' : ($delta <= -73.3 ? 'нет' : ($delta <= -45.6 ? 'низкая' : 'средняя'));

        return new SusceptibilityResult($state, 'кривая «'.$column.'» при pH₂ '.$pressure.' МПа: '.number_format($limit, 1, '.', '').' °C; ΔT = '.number_format($delta, 1, '.', '').' °C → '.$state.' (рис. 2.E.2.1)', details: ['dT' => $delta, 'tc' => $limit]);
    }
}
