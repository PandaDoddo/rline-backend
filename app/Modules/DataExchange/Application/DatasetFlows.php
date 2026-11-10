<?php

namespace App\Modules\DataExchange\Application;

use App\Modules\Installations\Models\Installation;
use App\Modules\ProcessFlows\Application\FlowInputValidation;
use App\Modules\ProcessFlows\Application\ProcessFlows;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Flow import orchestrates the same aggregate commands as HTTP inside the dataset transaction. */
final class DatasetFlows
{
    public function __construct(private readonly FlowInputValidation $inputs, private readonly ProcessFlows $flows) {}

    public function import(array $records, array $installations, int $actor, string $batch): array
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new \LogicException('Dataset flows require the enclosing import transaction.');
        }
        $allowed = array_fill_keys($installations, true);
        $pending = [];
        $origins = [];
        $created = [];
        $compositions = 0;
        foreach ($records as $index => $record) {
            if (! isset($allowed[$record['installation_id']])) {
                throw ValidationException::withMessages(['flow_nodes.'.$index.'.installation_id' => 'Поток должен относиться к установке этого набора.']);
            }
            $pending[$record['id']] = [$index, $record];
        }
        while ($pending) {
            $progress = false;
            foreach ($pending as $id => [$index,$record]) {
                $parent = $record['input']['parent_id'] ?? null;
                if ($parent !== null && ! isset($created[$parent])) {
                    continue;
                }
                try {
                    $installation = Installation::findOrFail($record['installation_id']);
                    $node = $this->flows->createNode($installation->id, $this->inputs->node($record['input'] + ['structure_version' => $installation->flow_structure_version]), $actor, $id);
                    if (array_key_exists('card', $record)) {
                        $node = $this->flows->updateCard($node->id, $this->inputs->card($record['card'] + ['version' => $node->version]), $actor);
                        $compositions += $node->composition()->count();
                    }
                    $origins[] = ['flow_id' => $node->id, 'batch_id' => $batch, 'source_id' => $record['source_id'], 'imported_version' => $node->version];
                    $created[$id] = true;
                    unset($pending[$id]);
                    $progress = true;
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ['flow_nodes.'.$index.'.'.$key => $messages])->all());
                }
            }
            if (! $progress) {
                throw ValidationException::withMessages(['flow_nodes' => 'В дереве потоков есть цикл или отсутствующий родитель; нельзя использовать родителя вне импортируемого набора.']);
            }
        }
        foreach (array_chunk($origins, 500) as $chunk) {
            $db->table('dataset_import_flows')->insert($chunk);
        }

        return ['flow_nodes' => count($origins), 'composition_lines' => $compositions, 'source_ids' => array_column($origins, 'flow_id', 'source_id')];
    }
}
