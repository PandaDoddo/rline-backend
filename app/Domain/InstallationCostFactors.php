<?php

namespace App\Domain;

class InstallationCostFactors
{
    public function tables(): array
    {
        return json_decode(file_get_contents(resource_path('catalogs/installation-cost-tables.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function describe(array $values, array $passport): array
    {
        $tables = $this->tables();
        $systems = collect($tables['systems'])->keyBy('n');
        $detection = $systems[$values['detsys'] ?? '']['cls'] ?? null;
        $isolation = $systems[$values['isosys'] ?? '']['cls'] ?? null;
        $pair = fn ($row) => $row['d'] === $detection && $row['i'] === $isolation;
        $release = collect($tables['release'])->first($pair);
        $duration = collect($tables['duration'])->first($pair);
        $mitigation = isset($values['mitsys']) ? $tables['mitigation'][(int) $values['mitsys'] - 1] ?? null : null;
        $area = $passport['area'] ?? null;
        $density = function ($key) use ($area, $passport) {
            if (! is_numeric($area) || $area <= 0 || ! is_numeric($passport[$key] ?? null)) {
                return null;
            }
            $value = $passport[$key] / $area;

            return is_finite($value) ? $value : null;
        };

        return ['reference_version' => $tables['version'], 'detection_class' => $detection, 'isolation_class' => $isolation,
            'fact_di' => isset($release['f']) ? (float) $release['f'] : null, 'adapted_pair' => (bool) ($release['add'] ?? false),
            'ld_max' => $duration['ld'] ?? null, 'factmit' => ($values['mitsys'] ?? null) === 'none' ? 0 : ($mitigation[4] ?? null),
            'effective_costfactor' => $values['costfactor'] ?? 1, 'effective_outagemult' => $values['outagemult'] ?? 1,
            'shift_density' => $density('stShift'), 'total_density' => $density('stAll')];
    }
}
