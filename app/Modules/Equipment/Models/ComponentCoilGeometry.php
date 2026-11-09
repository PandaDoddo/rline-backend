<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentCoilGeometry extends TenantModel
{
    protected $table = 'equipment_component_coil_geometries';

    protected $primaryKey = 'component_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['coil_form'];

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'component_id');
    }
}
