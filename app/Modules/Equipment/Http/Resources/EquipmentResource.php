<?php

namespace App\Modules\Equipment\Http\Resources;

use App\Modules\Diagnostics\Application\DiagnosticPlans;
use App\Modules\Diagnostics\Http\Resources\EquipmentInspectionResource;
use App\Modules\Equipment\Application\EquipmentOperatingScopes;
use App\Modules\Equipment\Application\EquipmentThickness;
use App\Modules\Equipment\Domain\CorrosionCalculator;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Installations\Http\Resources\InstallationResource;
use App\Modules\ProcessFlows\Application\EquipmentFlowContacts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Equipment */
class EquipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->resource->relationLoaded('components') && $this->resource->relationLoaded('inspections');
        $thickness = $detail ? app(EquipmentThickness::class)->read($this->resource) : null;
        $plans = $this->resource->getAttribute('schedule_diagnostics') ?? (app(DiagnosticPlans::class)->read([$this->id])[$this->id] ?? []);
        $condition = $detail ? app(CorrosionCalculator::class)->calculate($this->passport, $this->components->toArray(), $thickness, $this->inspections->toArray(), config('equipment.profiles')[$this->profile_code]['g'] === 'ves', $plans) : null;

        return ['id' => (string) $this->id, 'installation_id' => (string) $this->installation_id, 'profile_code' => $this->profile_code,
            'data_origin' => $this->resource->getAttribute('import_origin'),
            'passport' => (object) $this->passport, 'passport_qualifiers' => (object) $this->passport_qualifiers, 'checks' => (object) $this->checks, 'status' => $this->status, 'version' => (int) $this->version,
            'components' => EquipmentComponentResource::collection($this->whenLoaded('components')),
            'inspections' => EquipmentInspectionResource::collection($this->whenLoaded('inspections')),
            'thickness' => $this->when($detail, $thickness),
            'condition' => $this->when($detail, $condition),
            'operating_scopes' => $this->when($detail, fn () => app(EquipmentOperatingScopes::class)->read($this->resource)),
            'diagnostics' => $plans,
            'flows' => $this->when($detail, fn () => app(EquipmentFlowContacts::class)->read($this->resource)),
            'installation' => new InstallationResource($this->installation), 'created_by' => (int) $this->created_by,
            'updated_by' => (int) $this->updated_by, 'updated_at' => $this->updated_at->toIso8601String()];
    }
}
