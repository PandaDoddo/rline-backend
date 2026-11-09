<?php

namespace App\Modules\Rbi\Domain\Consequences;

final class FireConsequences
{
    public function calculate(array $input, array $release): array
    {
        $component = [];
        $injury = [];
        $fraction = $input['flamFrac'];
        $fluid = $input['fluid'];
        if ($fraction === 0 || $fraction === 0.0) {
            return ['component' => [0, 0, 0, 0], 'injury' => [0, 0, 0, 0], 'ignition_blend' => 0];
        }
        $ait = isset($fluid['aitC']) ? $fluid['aitC'] + 273.15 : null;
        $temperature = $release['temperature_k'];
        $blend = $fraction > 0 ? max(0, min(1, ($temperature - $ait + 55.6) / (2 * 55.6))) : 0;
        foreach (['component' => 'cf48', 'injury' => 'cf49'] as $target => $tableKey) {
            $result = [];
            foreach ($release['diameters'] as $index => $diameter) {
                $rate = $release['rate'][$index] * $fraction;
                $mass = $release['mass'][$index] * $fraction;
                $efficiency = $mass > 4536 ? 4 * log10(2.205 * $mass) - 15 : 1;
                $instant = $release['types'][$index] === 'мгновенный' ? 1 : ((string) $fluid['type'] === '0' ? min($rate / 25.2, 1) : 0);
                $areas = [];
                for ($row = 1; $row <= 4; $row++) {
                    [$a,$b] = CoefficientTable::pair($input[$tableKey], $row, $release['phase']);
                    $isInstant = $row >= 3;
                    $quantity = $isInstant ? $mass : $rate;
                    $areas[$row] = $diameter && $quantity > 0 && $a !== null ? $a * pow($quantity, $b) * (1 - $input['factmit']) / ($isInstant ? $efficiency : 1) : 0;
                }
                $withoutIgnition = $areas[3] * $instant + $areas[1] * (1 - $instant);
                $withIgnition = $areas[4] * $instant + $areas[2] * (1 - $instant);
                $result[] = $withIgnition * $blend + $withoutIgnition * (1 - $blend);
            }
            if ($target === 'component') {
                $component = $result;
            } else {
                $injury = $result;
            }
        }

        return ['component' => $component, 'injury' => $injury, 'ignition_blend' => $blend];
    }
}
