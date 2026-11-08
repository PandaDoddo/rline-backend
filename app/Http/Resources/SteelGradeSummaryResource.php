<?php

namespace App\Http\Resources;

use App\Models\SteelGrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SteelGrade */
class SteelGradeSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'name' => (string) $this->label, 'analog' => $this->summary_analog, 'subtype' => $this->summary_subtype, 'standard' => $this->summary_standard,
            'origin' => $this->origin, 'status' => $this->status, 'version' => (int) $this->version];
    }
}
