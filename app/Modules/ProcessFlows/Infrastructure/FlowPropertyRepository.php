<?php

namespace App\Modules\ProcessFlows\Infrastructure;

use App\Modules\ProcessFlows\Domain\FlowPropertySchema;
use App\Modules\ProcessFlows\Models\ProcessFlowNode;
use Illuminate\Support\Facades\DB;
use LogicException;

final class FlowPropertyRepository
{
    /** Requires the flow's aggregate version and installation locks. */
    public function save(ProcessFlowNode $flow, array $attributes, array $sources, array $checks): void
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Flow property persistence requires an aggregate transaction.');
        }
        $keys = array_keys(FlowPropertySchema::TYPES);
        if (array_diff([...array_keys($attributes), ...array_keys($sources), ...array_keys($checks)], $keys)) {
            throw new LogicException('Undeclared flow property.');
        }
        $db->table('process_flow_properties')->updateOrInsert(['flow_id' => $flow->id], array_replace(array_fill_keys($keys, null), $attributes));
        $annotations = [];
        foreach (array_unique([...array_keys($attributes), ...array_keys($sources), ...array_keys($checks)]) as $key) {
            $source = $sources[$key] ?? null;
            $check = $checks[$key] ?? null;
            $annotations[] = ['flow_id' => $flow->id, 'field_key' => $key, 'value_present' => array_key_exists($key, $attributes), 'source_present' => $source !== null,
                'citation' => $source['citation'] ?? null, 'conditions' => $source['conditions'] ?? null, 'source_by' => $source['by'] ?? null, 'source_at' => $source['at'] ?? null,
                'verified_by' => $check['by'] ?? null, 'verified_at' => $check['at'] ?? null];
        }
        $db->table('process_flow_property_annotations')->where('flow_id', $flow->id)->delete();
        if ($annotations) {
            $db->table('process_flow_property_annotations')->insert($annotations);
        }
        $flow->unsetRelation('cardProjection');
    }
}
