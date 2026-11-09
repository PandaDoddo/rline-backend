<?php

namespace App\Modules\Diagnostics\Http\Resources;

use App\Modules\Diagnostics\Models\EquipmentInspection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EquipmentInspection */
class EquipmentInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'kind' => $this->kind, 'is_primary' => (bool) $this->is_primary,
            'planned_on' => $this->planned_on?->format('Y-m-d'), 'performed_on' => $this->performed_on?->format('Y-m-d'),
            'report_number' => $this->report_number, 'notes' => $this->notes,
            'next_on' => $this->next_on?->format('Y-m-d'), 'organization' => $this->organization, 'inspection_type' => $this->inspection_type,
            'follow_up_dates' => (object) $this->follow_up_dates, 'unknown_fields' => $this->unknown_fields, 'verified' => $this->verified_at !== null];
    }
}
