<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;

class ComponentMechanismInputs extends TenantModel
{
    protected $table = 'equipment_component_mechanism_inputs';

    protected $primaryKey = 'component_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['vibration_excluded' => 'boolean', 'tank_floor' => 'boolean'];
    }
}
