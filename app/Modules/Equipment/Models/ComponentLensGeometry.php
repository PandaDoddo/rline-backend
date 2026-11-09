<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentLensGeometry extends TenantModel
{
    protected $table = 'equipment_component_lens_geometries';

    protected $primaryKey = 'component_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['outer_diameter_mm'];

    protected function casts(): array
    {
        return ['outer_diameter_mm' => 'float'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'component_id');
    }
}
