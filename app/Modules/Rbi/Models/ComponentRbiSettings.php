<?php

namespace App\Modules\Rbi\Models;

use App\Models\TenantModel;

class ComponentRbiSettings extends TenantModel
{
    protected $table = 'equipment_component_rbi_settings';

    protected $primaryKey = 'component_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['weld_efficiency' => 'float', 'injection_point' => 'boolean', 'dead_leg' => 'boolean'];
    }
}
