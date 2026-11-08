<?php

namespace App\Http\Resources;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Equipment */
class DiagnosticScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'installation_id' => (string) $this->installation_id, 'profile_code' => $this->profile_code,
            'passport' => (object) $this->passport, 'installation' => new InstallationResource($this->installation),
            'diagnostics' => $this->resource->getAttribute('schedule_diagnostics') ?? []];
    }
}
