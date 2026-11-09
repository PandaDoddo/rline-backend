<?php

namespace App\Modules\Catalogs\Http\Resources;

use App\Modules\Catalogs\Infrastructure\CatalogListProjection;
use App\Modules\Catalogs\Models\DamageMechanism;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DamageMechanism */
class DamageMechanismResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $values = $this->resource->effective();
        $ids = $values['related_ids'] ?? [];
        $related = $ids ? CatalogListProjection::damageMechanisms(DamageMechanism::whereIn('id', $ids))->get()->keyBy('id') : collect();
        $ordered = collect($ids)->map(fn ($id) => $related->get($id))->filter()->values();

        return ['id' => (string) $this->id, 'legacy_code' => (string) $this->legacy_code, 'origin' => $this->origin, 'base_version' => $this->base_version, 'values' => (object) $values, 'base_values' => (object) $this->base_values, 'overrides' => (object) $this->overrides, 'checks' => (object) $this->checks, 'sources' => (object) $this->sources, 'related' => DamageMechanismSummaryResource::collection($ordered), 'status' => $this->status, 'version' => (int) $this->version, 'updated_by' => $this->updated_by === null ? null : (int) $this->updated_by, 'updated_at' => $this->updated_at->toIso8601String()];
    }
}
