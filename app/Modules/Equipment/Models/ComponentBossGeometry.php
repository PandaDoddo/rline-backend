<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentBossGeometry extends TenantModel
{
    protected $table = 'equipment_component_boss_geometries';

    protected $primaryKey = 'component_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['design_type', 'height_mm'];

    protected function casts(): array
    {
        return ['height_mm' => 'float'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'component_id');
    }
}
