<?php

namespace App\Modules\Catalogs\Http\Resources;

use App\Modules\Catalogs\Domain\SubstanceClassification;
use App\Modules\Catalogs\Models\Substance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Substance */
class SubstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $values = $this->resource->effective();

        return ['id' => (string) $this->id, 'legacy_code' => $this->legacy_code, 'origin' => $this->origin, 'base_version' => $this->base_version,
            'data_origin' => $this->resource->getAttribute('import_origin'),
            'values' => (object) $values, 'base_values' => (object) $this->base_values, 'overrides' => (object) $this->overrides,
            'checks' => (object) $this->checks, 'sources' => (object) $this->sources, 'base_flags' => (object) $this->base_flags,
            'classification' => app(SubstanceClassification::class)->describe($values), 'status' => $this->status, 'version' => (int) $this->version,
            'needs_review' => $this->resource->needsReview(), 'updated_by' => $this->updated_by === null ? null : (int) $this->updated_by, 'updated_at' => $this->updated_at->toIso8601String()];
    }
}
