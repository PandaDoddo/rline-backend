<?php

namespace App\Http\Resources;

use App\Models\EquipmentInspection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EquipmentInspection */
class EquipmentInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'kind' => $this->kind, 'is_primary' => (bool) $this->is_primary,
            'planned_on' => $this->planned_on?->format('Y-m-d'), 'performed_on' => $this->performed_on?->format('Y-m-d'),
            'report_number' => $this->report_number, 'notes' => $this->notes];
    }
}
