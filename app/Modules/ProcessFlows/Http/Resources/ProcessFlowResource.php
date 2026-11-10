<?php

namespace App\Modules\ProcessFlows\Http\Resources;

use App\Modules\ProcessFlows\Domain\FlowScreening;
use App\Modules\ProcessFlows\Domain\MixtureCalculator;
use App\Modules\ProcessFlows\Infrastructure\FlowCompositionResolver;
use App\Modules\ProcessFlows\Models\ProcessFlowNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProcessFlowNode */
class ProcessFlowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $components = $this->kind === 'flow' ? app(FlowCompositionResolver::class)->resolve($this->resource) : [];
        $proposed = $this->kind === 'flow' && in_array($this->medium_kind, ['fluid', 'air'], true) ? app(MixtureCalculator::class)->calculate($this->basis, $components, $this->resource->attributes ?? []) : null;
        $engine = app(FlowScreening::class);
        $screening = $engine->eligible($this->category, $this->medium_kind, $this->kind) ? $engine->evaluate($this->screening) : null;
        $state = $this->screening;
        if ($state !== null) {
            foreach (['values', 'comments', 'thresholds'] as $key) {
                $state[$key] = (object) ($state[$key] ?? []);
            }
        }

        return (new ProcessFlowNodeResource($this->resource))->toArray($request) + ['basis' => (string) $this->basis, 'attributes' => (object) ($this->resource->attributes ?? []), 'sources' => (object) ($this->sources ?? []), 'checks' => (object) ($this->checks ?? []),
            'screening' => $state, 'screening_result' => $screening, 'composition' => $components, 'proposed_calculation' => $proposed, 'accepted_calculation' => $this->accepted_calculation,
            'calculation_stale' => $this->accepted_calculation !== null && $this->accepted_calculation['signature'] !== ($proposed['signature'] ?? null)];
    }
}
