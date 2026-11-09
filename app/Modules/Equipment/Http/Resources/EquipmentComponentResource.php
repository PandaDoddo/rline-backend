<?php

namespace App\Modules\Equipment\Http\Resources;

use App\Modules\Equipment\Application\ComponentAssemblies;
use App\Modules\Equipment\Application\ComponentConnections;
use App\Modules\Equipment\Application\ComponentHistory;
use App\Modules\Equipment\Application\ComponentSpecifications;
use App\Modules\Equipment\Models\EquipmentComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EquipmentComponent */
class EquipmentComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'parent_id' => $this->parent_id, 'code' => (string) $this->code,
            'name' => $this->name, 'kind' => $this->kind, 'type_code' => $this->type_code, 'is_coil' => (bool) $this->is_coil, 'status' => $this->status, 'flow_space' => $this->flow_space,
            'coil_role' => $this->coil_role,
            'connections' => app(ComponentConnections::class)->read($this->resource),
            'assemblies' => app(ComponentAssemblies::class)->read($this->resource),
            'history_count' => app(ComponentHistory::class)->count($this->resource),
            'position' => (int) $this->position, 'material' => $this->material, 'diameter_mm' => $this->diameter_mm,
            'length_mm' => $this->length_mm, 'nominal_thickness_mm' => $this->nominal_thickness_mm, 'rejection_passport_mm' => $this->rejection_passport_mm, 'rejection_epb_mm' => $this->rejection_epb_mm, 'notes' => $this->notes,
            ...app(ComponentSpecifications::class)->read($this->resource)];
    }
}
