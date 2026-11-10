<?php

namespace App\Modules\DataExchange\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Closed owner mapping: one provenance query per collection, never one per record. */
final class ImportedRecordOrigins
{
    private const OWNERS = [
        'flow' => ['table' => 'dataset_import_flows', 'key' => 'flow_id', 'source' => 'source_id', 'issue' => 'flow_id'],
        'substance' => ['table' => 'dataset_import_substances', 'key' => 'substance_id', 'source' => 'source_code', 'issue' => null],
        'installation' => ['table' => 'dataset_import_installations', 'key' => 'installation_id', 'source' => 'source_id', 'issue' => 'installation_id'],
        'maintenance' => ['table' => 'dataset_import_maintenance', 'key' => 'record_id', 'source' => 'source_reference', 'issue' => null],
    ];

    public function attach(Collection $models, string $owner): void
    {
        if ($models->isEmpty()) {
            return;
        }
        $definition = self::OWNERS[$owner] ?? throw new \InvalidArgumentException('Unsupported imported record owner.');
        $query = DB::connection('tenant')->table($definition['table'].' as o')->join('dataset_import_batches as b', 'b.id', '=', 'o.batch_id')
            ->whereIn('o.'.$definition['key'], $models->pluck('id'))->select(['o.'.$definition['key'].' as target_id', 'o.'.$definition['source'].' as source_id', 'o.imported_version', 'b.id as batch_id', 'b.source_kind', 'b.label', 'b.source_snapshot_at', 'b.applied_at']);
        $query->selectRaw($definition['issue'] ? "(SELECT count(*) FROM dataset_import_issues i WHERE i.{$definition['issue']}=o.{$definition['key']} AND i.severity IN ('warning','error')) AS source_issue_count" : '0 AS source_issue_count');
        if ($owner === 'maintenance') {
            $query->addSelect('o.source_verified');
        }
        $rows = $query->get()->keyBy('target_id');
        foreach ($models as $model) {
            $row = $rows->get($model->id);
            $model->setAttribute('import_origin', $row ? ['batch_id' => $row->batch_id, 'kind' => $row->source_kind, 'label' => $row->label, 'source_id' => $row->source_id,
                'source_snapshot_at' => Carbon::parse($row->source_snapshot_at)->toISOString(), 'applied_at' => Carbon::parse($row->applied_at)->toISOString(),
                'imported_version' => (int) $row->imported_version, 'changed_since_import' => (int) $model->version !== (int) $row->imported_version, 'source_issue_count' => (int) $row->source_issue_count] : null);
            if ($row && $owner === 'maintenance') {
                $model->setAttribute('import_origin', $model->getAttribute('import_origin') + ['source_verified' => $row->source_verified === null ? null : (bool) $row->source_verified]);
            }
        }
    }
}
