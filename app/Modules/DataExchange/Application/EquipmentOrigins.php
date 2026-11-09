<?php

namespace App\Modules\DataExchange\Application;

use App\Modules\Shared\Domain\ValueEquality;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Batched origin reads; child evidence is queried only for detailed imported cards. */
final class EquipmentOrigins
{
    public function attach(Collection $equipment): void
    {
        if ($equipment->isEmpty()) {
            return;
        }
        $rows = DB::connection('tenant')->table('dataset_import_equipment as o')->join('dataset_import_batches as b', 'b.id', '=', 'o.batch_id')
            ->whereIn('o.equipment_id', $equipment->pluck('id'))->select(['o.equipment_id', 'o.source_id', 'o.imported_version', 'b.id as batch_id', 'b.source_kind', 'b.label', 'b.source_snapshot_at', 'b.applied_at'])
            ->selectRaw('(SELECT count(*) FROM dataset_import_issues i WHERE i.equipment_id=o.equipment_id AND i.severity IN (\'warning\',\'error\')) AS source_issue_count')->get()->keyBy('equipment_id');
        if ($rows->isEmpty()) {
            foreach ($equipment as $model) {
                $model->setAttribute('import_origin', null);
            }

            return;
        }
        $fields = DB::connection('tenant')->table('dataset_import_passport_origins')->whereIn('equipment_id', $equipment->pluck('id'))->get()->groupBy('equipment_id');
        $detailIds = $equipment->filter(fn ($model) => $model->relationLoaded('components'))->pluck('id');
        $children = [];
        if ($detailIds->isNotEmpty()) {
            foreach (['component' => 'equipment_components', 'scope' => 'equipment_operating_scopes', 'cml' => 'equipment_cmls'] as $owner => $table) {
                $children[$owner] = DB::connection('tenant')->table('dataset_import_'.$owner.'_origins as o')->join($table.' as c', 'c.id', '=', 'o.'.$owner.'_id')
                    ->whereIn('o.equipment_id', $detailIds)->whereRaw('(to_jsonb(c)->o.field_key)=o.imported_value')->select(['o.equipment_id', 'o.'.$owner.'_id as id', 'o.field_key'])->orderBy('o.field_key')->get()->groupBy('equipment_id');
            }
        }
        foreach ($equipment as $model) {
            $row = $rows->get($model->id);
            $model->setAttribute('import_origin', $row ? ['batch_id' => $row->batch_id, 'kind' => $row->source_kind, 'label' => $row->label, 'source_id' => $row->source_id,
                'source_snapshot_at' => Carbon::parse($row->source_snapshot_at)->toISOString(), 'applied_at' => Carbon::parse($row->applied_at)->toISOString(),
                'imported_version' => (int) $row->imported_version, 'changed_since_import' => (int) $model->version !== (int) $row->imported_version, 'source_issue_count' => (int) $row->source_issue_count,
                'generated_passport_fields' => collect($fields->get($model->id, []))->filter(fn ($field) => ValueEquality::same($model->passport[$field->field_key] ?? null, json_decode($field->imported_value, true, flags: JSON_THROW_ON_ERROR)))->pluck('field_key')->sort()->values()->all(),
                'generated_component_fields' => $this->childFields($children['component'] ?? collect(), $model->id),
                'generated_scope_fields' => $this->childFields($children['scope'] ?? collect(), $model->id),
                'generated_cml_counts' => collect(($children['cml'] ?? collect())->get($model->id, []))->pluck('id')->unique()->sort()->values()->all()] : null);
        }
    }

    private function childFields(Collection $groups, string $equipment): array
    {
        return collect($groups->get($equipment, []))->groupBy('id')->map(fn ($fields, $id) => ['id' => $id, 'fields' => $fields->pluck('field_key')->sort()->values()->all()])->sortBy('id')->values()->all();
    }
}
