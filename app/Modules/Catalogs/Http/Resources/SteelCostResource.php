<?php

namespace App\Modules\Catalogs\Http\Resources;

use App\Modules\Catalogs\Domain\SteelMaterialCost;
use App\Modules\Catalogs\Models\SteelGrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SteelGrade */
class SteelCostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $values = $this->resource->effective();

        return ['id' => (string) $this->id, 'version' => (int) $this->version, 'name' => (string) $values['name'],
            'subtype' => $values['subtype'] ?? null, 'refine' => $values['refine'] ?? null,
            'cost' => app(SteelMaterialCost::class)->describe($values, $this->resource->getRelation('materialCostRows')),
            'overrides' => (object) array_intersect_key($this->overrides, array_flip(['accepted_cost', 'cost_reason'])),
            'checks' => (object) array_intersect_key($this->checks, array_flip(['accepted_cost', 'cost_reason'])),
            'sources' => (object) array_intersect_key($this->sources, array_flip(['accepted_cost', 'cost_reason']))];
    }
}
