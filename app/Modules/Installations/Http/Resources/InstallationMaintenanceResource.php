<?php

namespace App\Modules\Installations\Http\Resources;

use App\Modules\Installations\Application\InstallationMaintenance;
use App\Modules\Installations\Models\InstallationMaintenanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InstallationMaintenanceRecord */
class InstallationMaintenanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $values = $this->values ?? [];
        $duration = $this->kind === 'stop' && isset($values['starts_at'], $values['ends_at']) ? CarbonImmutable::parse($values['starts_at'])->diffInSeconds(CarbonImmutable::parse($values['ends_at'])) / 3600 : null;

        return ['id' => (string) $this->id, 'installation_id' => (string) $this->installation_id, 'kind' => (string) $this->kind, 'values' => (object) $values,
            'data_origin' => $this->resource->getAttribute('import_origin'),
            'check' => $this->check, 'complete' => collect(app(InstallationMaintenance::class)->requiredFields($this->kind))->every(fn ($key) => isset($values[$key])),
            'duration_hours' => $duration, 'performed_inspections' => (int) ($this->performed_inspections ?? 0), 'updated_at' => $this->updated_at->toIso8601String()];
    }
}
