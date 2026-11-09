<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentShellGeometry extends TenantModel
{
    protected $table = 'equipment_component_shell_geometries';

    protected $primaryKey = 'component_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['shell_form', 'small_diameter_mm', 'small_thickness_mm'];

    protected function casts(): array
    {
        return ['small_diameter_mm' => 'float', 'small_thickness_mm' => 'float'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'component_id');
    }
}
