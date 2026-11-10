<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ComponentMechanismOverride extends TenantModel
{
    use HasUuids;

    protected $table = 'equipment_mechanism_overrides';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';
}
