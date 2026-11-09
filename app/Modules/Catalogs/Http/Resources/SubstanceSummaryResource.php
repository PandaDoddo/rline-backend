<?php

namespace App\Modules\Catalogs\Http\Resources;

use App\Modules\Catalogs\Models\Substance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Substance */
class SubstanceSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'name' => (string) $this->label, 'formula' => $this->summary_formula, 'group' => $this->summary_group,
            'origin' => $this->origin, 'status' => $this->status, 'version' => (int) $this->version, 'has_review_flags' => $this->resource->needsReview()];
    }
}
