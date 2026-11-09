<?php

namespace App\Modules\Catalogs\Http\Resources;

use App\Modules\Catalogs\Application\SteelGradeAssessment;
use App\Modules\Catalogs\Models\SteelGrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SteelGrade */
class SteelGradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $values = $this->resource->effective();

        return ['id' => (string) $this->id, 'legacy_code' => $this->legacy_code, 'origin' => $this->origin, 'base_version' => $this->base_version,
            'values' => (object) $values, 'base_values' => (object) $this->base_values, 'overrides' => (object) $this->overrides,
            'checks' => (object) $this->checks, 'sources' => (object) $this->sources,
            'assessment' => app(SteelGradeAssessment::class)->describe($values), 'status' => $this->status, 'version' => (int) $this->version,
            'updated_by' => $this->updated_by === null ? null : (int) $this->updated_by, 'updated_at' => $this->updated_at->toIso8601String()];
    }
}
