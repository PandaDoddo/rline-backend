<?php

namespace App\Modules\Diagnostics\Http\Resources;

use App\Modules\Equipment\Domain\EquipmentIdentity;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Installations\Http\Resources\InstallationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Equipment */
class DiagnosticScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'installation_id' => (string) $this->installation_id, 'profile_code' => $this->profile_code,
            'technical_position' => EquipmentIdentity::position($this->passport),
            'passport' => (object) $this->passport, 'passport_qualifiers' => (object) $this->passport_qualifiers, 'installation' => new InstallationResource($this->installation),
            'diagnostics' => $this->resource->getAttribute('schedule_diagnostics') ?? []];
    }
}
