<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;

/** A source identifier whose physical component has not been established. */
class UnresolvedComponentTarget extends TenantModel
{
    protected $table = 'equipment_component_unresolved_targets';

    public $timestamps = false;
}
