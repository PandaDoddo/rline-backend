<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;

class ComponentAssemblyMember extends TenantModel
{
    protected $table = 'equipment_component_assembly_members';

    public $timestamps = false;
}
