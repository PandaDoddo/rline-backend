<?php

namespace App\Http\Resources;

use App\Models\EquipmentComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EquipmentComponent */
class EquipmentComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'parent_id' => $this->parent_id, 'code' => (string) $this->code,
            'name' => $this->name, 'kind' => $this->kind, 'is_coil' => (bool) $this->is_coil, 'status' => $this->status, 'flow_space' => $this->flow_space,
            'position' => (int) $this->position, 'material' => $this->material, 'diameter_mm' => $this->diameter_mm,
            'length_mm' => $this->length_mm, 'nominal_thickness_mm' => $this->nominal_thickness_mm, 'rejection_passport_mm' => $this->rejection_passport_mm, 'rejection_epb_mm' => $this->rejection_epb_mm, 'notes' => $this->notes];
    }
}
