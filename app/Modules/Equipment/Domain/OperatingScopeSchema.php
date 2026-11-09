<?php

namespace App\Modules\Equipment\Domain;

/** Frozen scope ownership: base passport is the shell, each coil owns its separate parameters. */
final class OperatingScopeSchema
{
    public const VERSION = 'equipment-operating-scopes-v1';

    public const FIELDS = [
        'pressure_test_mpa' => ['tube', 'coil'], 'pressure_design_mpa' => ['tube', 'coil'], 'pressure_allowed_mpa' => ['tube', 'coil'], 'pressure_operating_mpa' => ['tube', 'coil'], 'pressure_installation_mpa' => ['coil'],
        'temperature_design_c' => ['tube', 'coil'], 'temperature_allowed_c' => ['tube'], 'temperature_max_c' => ['tube', 'coil'], 'temperature_min_c' => ['tube', 'coil'],
        'temperature_mdmt_c' => ['tube', 'coil'], 'temperature_brittle_transition_c' => ['tube', 'coil'], 'temperature_shutdown_trip_c' => ['coil'], 'temperature_shutdown_c' => ['tube', 'coil'], 'temperature_upset_c' => ['tube', 'coil'],
        'relief_valve_present' => ['tube', 'coil'], 'medium' => ['tube', 'coil'], 'alternative_medium' => ['coil'], 'volume_m3' => ['tube'], 'flow_velocity_m_s' => ['tube'], 'corrosion_allowance_mm' => ['coil'], 'design_corrosion_mm_year' => ['coil'],
    ];

    public static function fields(): array
    {
        $fields = [];
        foreach (self::FIELDS as $key => $kinds) {
            $definition = $key === 'alternative_medium' ? ['label' => 'Альтернативная среда', 'section' => 'Технологическая среда', 'rules' => 'nullable|string|max:255', 'legacy_keys' => ['Технологическая среда||рабочая альтернативная']] : config('equipment.passport.'.$key);
            $legacy = [];
            foreach ($kinds as $kind) {
                foreach ($definition['legacy_keys'] ?? [] as $path) {
                    $legacy[] = ($kind === 'tube' ? 'ТП|' : 'ЗМ|').$path;
                }
            }
            unset($definition['groups']);
            $definition['legacy_keys'] = array_values(array_unique($legacy));
            $definition['scopes'] = $kinds;
            $definition['key'] = $key;
            $fields[$key] = $definition;
        }

        return $fields;
    }
}
