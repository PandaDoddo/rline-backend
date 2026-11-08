<?php

namespace App\Http\Resources;

use App\Domain\InstallationCostFactors;
use App\Models\Installation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Installation */
class InstallationCostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'version' => (int) $this->version, 'values' => (object) ($this->cost_values ?? []),
            'sources' => (object) ($this->cost_sources ?? []), 'checks' => (object) ($this->cost_checks ?? []),
            'derived' => app(InstallationCostFactors::class)->describe($this->cost_values ?? [], $this->passport ?? [])];
    }
}
