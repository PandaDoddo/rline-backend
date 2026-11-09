<?php

namespace App\Modules\Rbi\Domain\Consequences;

/** SI release model; pressures supplied as MPa gauge, temperature in Celsius. */
final class ReleaseModel
{
    public function calculate(array $input): array
    {
        $fluid = $input['fluid'];
        $gas = $input['phase'] === 'газ' || $input['phase'] === 'пар';
        $releasePhase = $gas ? 'газ' : ($fluid['phaseNC'] === 'газ' && $fluid['nbp'] <= 26 ? 'газ' : 'жидкость');
        $diameters = [6.4, 25, 102, 406];
        if ($input['pipeRule']) {
            $diameters = array_map(fn ($diameter, $index) => $index === 3 ? min($input['D'], 406) : ($input['D'] <= $diameter ? 0 : $diameter), $diameters, array_keys($diameters));
            if ($input['D'] <= 6.4) {
                $diameters = [0, 0, 0, $input['D']];
            }
        }
        $temperature = $input['T'] + 273.15;
        $pressure = ($input['P'] + 0.101325) * 1000;
        $k = $fluid['k'] ?? null;
        $transition = $gas ? 101.325 * pow(($k + 1) / 2, $k / ($k - 1)) : null;
        $sonic = $gas && $pressure > $transition;
        $discharge = function (float $area) use ($gas, $fluid, $pressure, $temperature, $k, $sonic): float {
            if ($area <= 0 || $pressure <= 101.325) {
                return 0;
            }
            if (! $gas) {
                return 0.61 * $fluid['rhoL'] * $area / 31623 * sqrt(2 * ($pressure - 101.325) / $fluid['rhoL']);
            }
            if ($sonic) {
                return 0.9 / 1000 * $area * $pressure * sqrt($k * $fluid['mw'] / (8314 * $temperature) * pow(2 / ($k + 1), ($k + 1) / ($k - 1)));
            }
            $ratio = 101.325 / $pressure;

            return 0.9 / 1000 * $area * $pressure * sqrt($fluid['mw'] / (8314 * $temperature) * (2 * $k / ($k - 1)) * pow($ratio, 2 / $k) * (1 - pow($ratio, ($k - 1) / $k)));
        };
        $areas = array_map(fn ($diameter) => M_PI * $diameter * $diameter / 4, $diameters);
        $theoretical = array_map($discharge, $areas);
        $maximum = $discharge(32450);
        $density = $gas ? $input['rhoG'] : $fluid['rhoL'];
        $componentMass = $density * $input['Vk'];
        $inventoryMass = $density * max($input['Vgz'], $input['Vk']);
        $available = [];
        $types = [];
        $rate = [];
        $duration = [];
        $mass = [];
        $toxicDuration = [];
        foreach ($diameters as $index => $diameter) {
            $available[$index] = $diameter ? min($componentMass + 180 * min($theoretical[$index], $maximum), $inventoryMass) : 0;
            $types[$index] = ! $diameter ? '' : ($diameter <= 6.4 ? 'непрерывный' : (min($theoretical[$index] * 180, $available[$index]) > 4536 ? 'мгновенный' : 'непрерывный'));
            $rate[$index] = $theoretical[$index] * (1 - $input['factdi']);
            $duration[$index] = $rate[$index] > 0 ? min($available[$index] / $rate[$index], 60 * $input['ldmax'][$index]) : 0;
            $mass[$index] = min($rate[$index] * $duration[$index], $available[$index]);
            $toxicDuration[$index] = $rate[$index] > 0 ? min(3600, $mass[$index] / $rate[$index], 60 * $input['ldmax'][$index]) : 0;
        }

        return ['diameters' => $diameters, 'areas' => $areas, 'theoretical' => $theoretical, 'available' => $available, 'types' => $types, 'rate' => $rate, 'duration' => $duration, 'mass' => $mass, 'toxic_duration' => $toxicDuration,
            'phase' => $releasePhase, 'temperature_k' => $temperature, 'pressure_kpa' => $pressure, 'transition_kpa' => $transition, 'sonic' => $gas ? $sonic : null, 'component_mass' => $componentMass, 'inventory_mass' => $inventoryMass];
    }
}
