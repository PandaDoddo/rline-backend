<?php

namespace App\Http\Resources;

use App\Models\MaterialCost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaterialCost */
class MaterialCostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'legacy_code' => (string) $this->legacy_code, 'group' => (string) $this->material_group,
            'english_name' => (string) $this->english_name, 'adapted' => (bool) $this->adapted, 'base_version' => (string) $this->base_version,
            'values' => (object) $this->resource->effective(), 'base_values' => (object) $this->base_values, 'overrides' => (object) $this->overrides,
            'sources' => (object) $this->sources, 'checks' => (object) $this->checks, 'version' => (int) $this->version,
            'updated_at' => $this->updated_at->toIso8601String()];
    }
}
