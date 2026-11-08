<?php

namespace App\Http\Resources;

use App\Domain\CorrosionCalculator;
use App\Domain\EquipmentFlowContacts;
use App\Domain\EquipmentThickness;
use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Equipment */
class EquipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->resource->relationLoaded('components') && $this->resource->relationLoaded('inspections');
        $thickness = $detail ? app(EquipmentThickness::class)->read($this->resource) : null;
        $condition = $detail ? app(CorrosionCalculator::class)->calculate($this->passport, $this->components->toArray(), $thickness, $this->inspections->toArray(), config('equipment.profiles')[$this->profile_code]['g'] === 'ves') : null;

        return ['id' => (string) $this->id, 'installation_id' => (string) $this->installation_id, 'profile_code' => $this->profile_code,
            'passport' => (object) $this->passport, 'checks' => (object) $this->checks, 'status' => $this->status, 'version' => (int) $this->version,
            'components' => EquipmentComponentResource::collection($this->whenLoaded('components')),
            'inspections' => EquipmentInspectionResource::collection($this->whenLoaded('inspections')),
            'thickness' => $this->when($detail, $thickness),
            'condition' => $this->when($detail, $condition),
            'flows' => $this->when($detail, fn () => app(EquipmentFlowContacts::class)->read($this->resource)),
            'installation' => new InstallationResource($this->installation), 'created_by' => (int) $this->created_by,
            'updated_by' => (int) $this->updated_by, 'updated_at' => $this->updated_at->toIso8601String()];
    }
}
