<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;

/** Read-only SQL projection; source facts live in typed component tables. */
class ComponentCardProjection extends TenantModel
{
    protected $table = 'equipment_component_cards';

    protected $primaryKey = 'component_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['construction' => 'array', 'head_geometry' => 'array', 'fitting_geometry' => 'array', 'end_connection' => 'array', 'boss_geometry' => 'array', 'valve_details' => 'array', 'tube_geometry' => 'array', 'coil_geometry' => 'array', 'lens_geometry' => 'array', 'expansion_joint' => 'array', 'shell_geometry' => 'array', 'ports' => 'array', 'connections' => 'array', 'history_count' => 'integer', 'assemblies' => 'array'];
    }
}
