<?php

namespace App\Modules\Rbi\Domain\Consequences;

final class FinancialConsequences
{
    public function calculate(array $input, array $release, float $componentArea, float $injuryArea): array
    {
        $weights = $input['gff'];
        $total = array_sum($weights);
        $weighted = fn ($values) => array_sum(array_map(fn ($value, $index) => $value * $weights[$index], $values, array_keys($values))) / $total;
        $cost = $input['cost'];
        $component = $weighted($input['holecost']) * $input['matcost'] * $cost['costfactor'];
        $affected = $componentArea * $cost['equipcost'] / 1e6;
        $componentOutage = $weighted(array_map(fn ($value) => $value === 'N' ? 0 : $value, $input['outage'])) * $cost['outagemult'];
        $usd = $affected * 1e6 / 100;
        $affectedOutage = $usd > 0 ? pow(10, 1.242 + 0.585 * log10($usd * 1e-6)) : 0;
        $production = ($componentOutage + $affectedOutage) * $cost['prodcost'] * $input['proddecrease'] / 100 / 1e6;
        $injury = $injuryArea * $cost['popdens'] * $cost['injcost'] / 1e6;
        $volumes = array_map(fn ($mass) => $release['phase'] === 'жидкость' ? $mass * (1 - $input['fluid']['fe']) / $input['fluid']['rhoL'] : 0, $release['mass']);
        $environment = $weighted($volumes) * $cost['envcost'] / 1e6;

        return ['FC' => $component + $affected + $production + $injury + $environment, 'FCc' => $component, 'FCa' => $affected, 'FCp' => $production, 'FCi' => $injury, 'FCe' => $environment,
            'component_outage_days' => $componentOutage, 'affected_outage_days' => $affectedOutage, 'spill_volumes' => $volumes, 'currency_policy' => 'legacy-v24.4: 100 RUB/USD for equation 3.62 only'];
    }
}
