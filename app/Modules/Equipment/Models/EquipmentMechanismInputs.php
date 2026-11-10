<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;

class EquipmentMechanismInputs extends TenantModel
{
    protected $table = 'equipment_mechanism_inputs';

    protected $primaryKey = 'equipment_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['vibration_present' => 'boolean', 'hydrogen_partial_pressure_mpa' => 'float'];
    }
}
