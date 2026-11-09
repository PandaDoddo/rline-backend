<?php

namespace App\Modules\ProcessFlows\Domain;

/** Frozen v1 physical mapping; pH has one owner in process_flow_properties. */
final class FlowEnvironmentSchema
{
    public const VERSION = 'flow-environment-v1';

    public const COLUMNS = [
        'water' => ['water_present', 'boolean'], 'clOn' => ['chlorides_present', 'boolean'], 'cl' => ['chlorides_ppm', 'double'],
        'h2s' => ['hydrogen_sulfide_present', 'boolean'], 'co2' => ['carbon_dioxide_present', 'boolean'], 'h2' => ['hydrogen_present', 'boolean'], 'pH2' => ['hydrogen_partial_pressure_mpa', 'double'],
        'h2sW' => ['aqueous_hydrogen_sulfide_ppm', 'double'], 'pH2S' => ['hydrogen_sulfide_partial_pressure_mpa', 'double'], 'cn' => ['cyanides_ppm', 'double'], 'co3' => ['carbonates_ppm', 'double'],
        'naoh' => ['caustic_percent', 'double'], 'o2w' => ['dissolved_oxygen_ppm', 'double'], 'dep' => ['deposits_present', 'boolean'], 'amRich' => ['rich_amine', 'boolean'],
        'amMD' => ['mea_or_dipa_present', 'boolean'], 'amDEA' => ['dea_present', 'boolean'], 'psOp' => ['polythionic_operating_conditions', 'boolean'], 'psSd' => ['polythionic_shutdown_conditions', 'boolean'],
        'hcl' => ['hydrogen_chloride_present', 'boolean'], 'hf' => ['hydrogen_fluoride_present', 'boolean'], 'h2so4' => ['sulfuric_acid_present', 'boolean'], 'amine' => ['amine_present', 'boolean'],
        'caustic' => ['caustic_present', 'boolean'], 'o2' => ['oxygen_present', 'boolean'], 'sulf' => ['sulfur_present', 'boolean'], 'sbc' => ['sour_brine_conditions', 'boolean'], 'cw' => ['cooling_water', 'boolean'],
    ];

    public const ALIASES = ['ph' => ['process_flow_properties', 'ph', 'double']];
}
