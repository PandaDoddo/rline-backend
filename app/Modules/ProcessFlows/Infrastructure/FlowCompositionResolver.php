<?php

namespace App\Modules\ProcessFlows\Infrastructure;

use App\Modules\Catalogs\Infrastructure\SubstanceCardProjection;
use App\Modules\Catalogs\Models\Substance;
use App\Modules\ProcessFlows\Models\ProcessFlowNode;

class FlowCompositionResolver
{
    public function resolve(ProcessFlowNode $flow): array
    {
        $flow->loadMissing('composition');
        $effective = SubstanceCardProjection::effectiveSql();
        $substances = Substance::without('cardProjection')->whereIn('id', $flow->composition->pluck('substance_id')->filter()->unique())->select(['id', 'version', 'status'])
            ->selectRaw("(SELECT coalesce(jsonb_object_agg(key, value), '{}'::jsonb) FROM jsonb_each(".$effective.") WHERE key IN ('name','mw','nbp','dens','flammable','toxic','nfnt','inert','formula','repEnv','api581cls','idlh')) AS resolved_values")->get()->keyBy('id');

        return $flow->composition->map(function ($row) use ($substances) {
            $substance = $substances->get($row->substance_id);

            return $row->only(['id', 'substance_id', 'role', 'name', 'amount', 'unit', 'plus', 'notes', 'position']) + ['substance_version' => $substance?->version, 'substance_status' => $substance?->status,
                'substance_values' => $substance ? json_decode($substance->getRawOriginal('resolved_values'), true, flags: JSON_THROW_ON_ERROR) : null];
        })->all();
    }
}
