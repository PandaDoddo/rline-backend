<?php

namespace App\Http\Resources;

use App\Domain\FlowCompositionResolver;
use App\Domain\MixtureCalculator;
use App\Models\ProcessFlowNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProcessFlowNode */
class ProcessFlowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $components = $this->kind === 'flow' ? app(FlowCompositionResolver::class)->resolve($this->resource) : [];
        $proposed = $this->kind === 'flow' && $this->medium_kind === 'fluid' ? app(MixtureCalculator::class)->calculate($this->basis, $components) : null;

        return (new ProcessFlowNodeResource($this->resource))->toArray($request) + ['basis' => (string) $this->basis, 'attributes' => (object) ($this->resource->attributes ?? []), 'sources' => (object) ($this->sources ?? []), 'checks' => (object) ($this->checks ?? []),
            'composition' => $components, 'proposed_calculation' => $proposed, 'accepted_calculation' => $this->accepted_calculation,
            'calculation_stale' => $this->accepted_calculation !== null && $this->accepted_calculation['signature'] !== ($proposed['signature'] ?? null)];
    }
}
