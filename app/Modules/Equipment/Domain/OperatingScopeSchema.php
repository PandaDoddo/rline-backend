<?php

namespace App\Modules\Equipment\Domain;

/** Frozen scope ownership: base passport is the shell, each coil owns its separate parameters. */
final class OperatingScopeSchema
{
    public const VERSION = 'equipment-operating-scopes-v2';

    public const FIELDS = [
        'pressure_test_mpa' => ['tube', 'coil'], 'pressure_design_mpa' => ['tube', 'coil'], 'pressure_allowed_mpa' => ['tube', 'coil'], 'pressure_operating_mpa' => ['tube', 'coil'], 'pressure_installation_mpa' => ['coil'],
        'temperature_design_c' => ['tube', 'coil'], 'temperature_allowed_c' => ['tube'], 'temperature_max_c' => ['tube', 'coil'], 'temperature_min_c' => ['tube', 'coil'],
        'temperature_mdmt_c' => ['tube', 'coil'], 'temperature_brittle_transition_c' => ['tube', 'coil'], 'temperature_shutdown_trip_c' => ['coil'], 'temperature_shutdown_c' => ['tube', 'coil'], 'temperature_upset_c' => ['tube', 'coil'],
        'relief_valve_present' => ['tube', 'coil'], 'medium' => ['tube', 'coil'], 'alternative_medium' => ['coil'], 'volume_m3' => ['tube'], 'flow_velocity_m_s' => ['tube', 'coil'], 'corrosion_allowance_mm' => ['tube', 'coil'], 'design_corrosion_mm_year' => ['tube', 'coil'],
        'external_coating_present' => ['tube', 'coil'], 'external_coating_applied_on' => ['tube', 'coil'], 'external_coating_life_years' => ['tube', 'coil'],
        'external_coating_quality' => ['tube', 'coil'], 'external_coating_application_quality' => ['tube', 'coil'], 'external_coating_notes' => ['tube', 'coil'],
        'insulation_present' => ['tube', 'coil'], 'insulation_chlorides' => ['tube', 'coil'], 'insulation_notes' => ['tube', 'coil'],
        'ground_location' => ['tube', 'coil'], 'ground_air_transition' => ['tube', 'coil'], 'moisture_accumulation' => ['tube', 'coil'], 'construction_complexity' => ['tube', 'coil'],
        'corrosion_monitoring' => ['tube', 'coil'], 'atmospheric_conditions' => ['tube', 'coil'],
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
            if (($definition['type'] ?? null) === 'date') {
                $definition['rules'] .= '|after_or_equal:0001-01-01|before_or_equal:9999-12-31';
            }
            $definition['legacy_keys'] = array_values(array_unique($legacy));
            $definition['scopes'] = $kinds;
            $definition['key'] = $key;
            $fields[$key] = $definition;
        }

        return $fields;
    }
}
