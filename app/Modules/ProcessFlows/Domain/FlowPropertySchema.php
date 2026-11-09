<?php

namespace App\Modules\ProcessFlows\Domain;

final class FlowPropertySchema
{
    public const VERSION = 'flow-properties-v2';

    public const TYPES = ['temperature' => 'double', 'pressure' => 'double', 'flow_rate_t_h' => 'double', 'velocity_m_s' => 'double', 'ph' => 'double', 'density' => 'double', 'molecular_weight' => 'double', 'boiling_point' => 'double', 'phase' => 'text', 'rep_environment' => 'text', 'soil_type' => 'text', 'insulation_type' => 'text', 'insulation_chlorides' => 'text', 'notes' => 'text'];
}
