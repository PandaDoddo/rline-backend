<?php

namespace App\Modules\ProcessFlows\Infrastructure;

use App\Modules\ProcessFlows\Domain\FlowEnvironmentSchema;
use App\Modules\ProcessFlows\Models\ProcessFlowNode;
use App\Modules\Shared\Domain\ValueEquality;
use Illuminate\Support\Facades\DB;
use LogicException;

final class FlowEnvironmentRepository
{
    public function save(ProcessFlowNode $flow, array $state): void
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Environment persistence requires an aggregate transaction.');
        }
        if (array_diff(array_keys($state['values'] ?? []), [...array_keys(FlowEnvironmentSchema::COLUMNS), ...array_keys(FlowEnvironmentSchema::ALIASES)])) {
            throw new LogicException('Undeclared flow environment property.');
        }
        if (! ValueEquality::same($state['values']['ph'] ?? null, $flow->getAttribute('attributes')['ph'] ?? null)) {
            throw new LogicException('Environmental pH must match the canonical physical property.');
        }
        $row = ['updated_by' => $state['updated_by'], 'updated_at' => $state['updated_at']];
        foreach (FlowEnvironmentSchema::COLUMNS as $key => [$column]) {
            $row[$column] = $state['values'][$key] ?? null;
        }
        $db->table('process_flow_environments')->updateOrInsert(['flow_id' => $flow->id], $row);
        $db->table('process_flow_screening_comments')->where('flow_id', $flow->id)->delete();
        $comments = [];
        foreach ($state['comments'] ?? [] as $key => $comment) {
            $comments[] = ['flow_id' => $flow->id, 'item_id' => $key, 'comment' => $comment];
        }
        if ($comments) {
            $db->table('process_flow_screening_comments')->insert($comments);
        }
        $db->table('process_flow_screening_thresholds')->where('flow_id', $flow->id)->delete();
        $thresholds = [];
        foreach ($state['thresholds'] ?? [] as $key => $value) {
            $thresholds[] = ['flow_id' => $flow->id, 'question_id' => $key, 'bound_count' => count($value['threshold']), 'bound_1' => $value['threshold'][0], 'bound_2' => $value['threshold'][1] ?? null, 'near_count' => count($value['near']), 'near_1' => $value['near'][0] ?? null, 'near_2' => $value['near'][1] ?? null];
        }
        if ($thresholds) {
            $db->table('process_flow_screening_thresholds')->insert($thresholds);
        }
        $flow->unsetRelation('screeningProjection');
    }
}
