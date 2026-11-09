<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentFittingGeometry extends TenantModel
{
    protected $table = 'equipment_component_fitting_geometries';

    protected $primaryKey = 'component_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['bend_radius_designation', 'bend_angle_deg', 'large_diameter_mm', 'large_thickness_mm', 'small_diameter_mm', 'small_thickness_mm', 'reducer_form', 'main_diameter_mm', 'main_thickness_mm', 'branch_diameter_mm', 'branch_thickness_mm', 'collar_present', 'collar_diameter_mm', 'collar_thickness_mm', 'collar_material', 'collar_hole_present', 'collar_hole_thread', 'collar_seam_type'];

    protected function casts(): array
    {
        return ['bend_angle_deg' => 'float', 'large_diameter_mm' => 'float', 'large_thickness_mm' => 'float', 'small_diameter_mm' => 'float', 'small_thickness_mm' => 'float', 'main_diameter_mm' => 'float', 'main_thickness_mm' => 'float', 'branch_diameter_mm' => 'float', 'branch_thickness_mm' => 'float', 'collar_diameter_mm' => 'float', 'collar_thickness_mm' => 'float', 'collar_present' => 'boolean', 'collar_hole_present' => 'boolean'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'component_id');
    }
}
