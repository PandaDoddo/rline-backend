<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EquipmentComponent extends TenantModel
{
    use HasUuids;

    protected $fillable = ['name', 'status', 'position', 'material', 'diameter_mm', 'length_mm', 'nominal_thickness_mm', 'rejection_passport_mm', 'rejection_epb_mm', 'notes', 'flow_space'];

    protected $attributes = ['next_element_number' => 1, 'position' => 0, 'status' => 'active', 'is_coil' => false];

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_coil' => 'boolean', 'next_element_number' => 'integer',
            'diameter_mm' => 'float', 'length_mm' => 'float', 'nominal_thickness_mm' => 'float', 'rejection_passport_mm' => 'float', 'rejection_epb_mm' => 'float'];
    }
}
