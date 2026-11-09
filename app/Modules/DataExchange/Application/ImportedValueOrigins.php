<?php

namespace App\Modules\DataExchange\Application;

use App\Modules\Equipment\Models\Equipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Immutable evidence is separate from the validated business write commands. */
final class ImportedValueOrigins
{
    public function record(Equipment $equipment, array $source, array $stages, string $batch, string $kind): void
    {
        $db = DB::connection('tenant');
        $generated = $source['generated_passport_fields'] ?? [];
        if ($kind !== 'demo_expanded' && ($generated || ! empty($source['generated_component_fields']) || ! empty($source['generated_cml_counts']) || ! empty($source['generated_scope_fields']) || collect($source['thickness']['stages'] ?? [])->contains(fn ($stage) => collect($stage['measurements'] ?? [])->contains(fn ($row) => in_array('demo_generated', $row['point_origins'] ?? [], true))))) {
            throw ValidationException::withMessages(['source' => 'Сгенерированные значения допустимы только в явно обозначенном демонаборе.']);
        }
        $passportRows = [];
        foreach ($generated as $field) {
            if (! array_key_exists($field, $source['create']['passport']) || ! array_key_exists($field, $equipment->passport)) {
                throw ValidationException::withMessages(['generated_passport_fields' => 'Отметка генерации должна ссылаться на переданное поле паспорта.']);
            }
            $passportRows[] = ['equipment_id' => $equipment->id, 'batch_id' => $batch, 'field_key' => $field,
                'imported_value' => json_encode($equipment->passport[$field], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
        }
        if ($passportRows) {
            $db->table('dataset_import_passport_origins')->insert($passportRows);
        }
        $this->recordChildren($equipment, $source, $batch);
        $measurements = $db->table('equipment_thickness_values')->where('equipment_id', $equipment->id)->get()->keyBy(fn ($row) => $row->stage_id.'|'.$row->cml_id);
        $pointRows = [];
        foreach ($stages as $stage) {
            foreach ($stage['measurements'] as $row) {
                $origins = $row['point_origins'] ?? array_fill(0, count($row['points']), 'stored_snapshot');
                if (count($origins) !== count($row['points'])) {
                    throw ValidationException::withMessages(['point_origins' => 'Для каждой точки укажите её происхождение.']);
                }
                $measurement = $measurements->get($stage['id'].'|'.$row['cml_id']);
                if (! $measurement) {
                    throw ValidationException::withMessages(['point_origins' => 'Не найдены сохранённые замеры для исходных точек.']);
                }
                foreach ($row['points'] as $index => $value) {
                    $pointRows[] = ['equipment_id' => $equipment->id, 'batch_id' => $batch, 'measurement_id' => $measurement->id,
                        'point_number' => $index + 1, 'kind' => $origins[$index], 'imported_thickness_mm' => $value];
                }
            }
        }
        foreach (array_chunk($pointRows, 1000) as $chunk) {
            $db->table('dataset_import_point_origins')->insert($chunk);
        }
    }

    private function recordChildren(Equipment $equipment, array $source, string $batch): void
    {
        $db = DB::connection('tenant');
        $specifications = [
            'component' => ['table' => 'equipment_components', 'inputs' => collect($source['components'] ?? [])->keyBy('id'), 'fields' => $source['generated_component_fields'] ?? []],
            'cml' => ['table' => 'equipment_cmls', 'inputs' => collect($source['thickness']['cmls'] ?? [])->keyBy('id'), 'fields' => array_map(fn ($id) => ['cml_id' => $id, 'field' => 'point_count'], $source['generated_cml_counts'] ?? [])],
        ];
        foreach ($specifications as $owner => $spec) {
            if (! $spec['fields']) {
                continue;
            }
            $stored = $db->table($spec['table'])->where('equipment_id', $equipment->id)->get()->keyBy('id');
            $rows = [];
            $seen = [];
            foreach ($spec['fields'] as $descriptor) {
                $id = $descriptor[$owner.'_id'];
                $field = $descriptor['field'];
                $key = $id.'|'.$field;
                if (isset($seen[$key]) || ! array_key_exists($field, $spec['inputs']->get($id, [])) || ! $stored->has($id)) {
                    throw ValidationException::withMessages(['generated_'.$owner.'_fields' => 'Отметка генерации должна однократно ссылаться на переданное поле своего объекта.']);
                }
                $seen[$key] = true;
                $rows[] = [$owner.'_id' => $id, 'equipment_id' => $equipment->id, 'batch_id' => $batch, 'field_key' => $field, 'imported_value' => json_encode($stored[$id]->$field, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->table('dataset_import_'.$owner.'_origins')->insert($chunk);
            }
        }
        $descriptors = $source['generated_scope_fields'] ?? [];
        if (! $descriptors) {
            return;
        }
        $key = fn ($row) => $row['kind'].':'.($row['component_id'] ?? '');
        $inputs = collect($source['operating_scopes'] ?? [])->keyBy($key);
        $stored = $db->table('equipment_operating_scopes')->where('equipment_id', $equipment->id)->get()->keyBy(fn ($row) => $row->kind.':'.($row->component_id ?? ''));
        $rows = [];
        $seen = [];
        foreach ($descriptors as $descriptor) {
            $scopeKey = $key($descriptor);
            $scope = $stored->get($scopeKey);
            foreach ($descriptor['fields'] as $field) {
                $identity = $scopeKey.'|'.$field;
                if (! $scope || ! property_exists($scope, $field) || isset($seen[$identity]) || ! array_key_exists($field, $inputs->get($scopeKey, [])['values'] ?? [])) {
                    throw ValidationException::withMessages(['generated_scope_fields' => 'Отметка генерации должна однократно ссылаться на переданный параметр своего пространства.']);
                }
                $seen[$identity] = true;
                $rows[] = ['scope_id' => $scope->id, 'equipment_id' => $equipment->id, 'batch_id' => $batch, 'field_key' => $field, 'imported_value' => json_encode($scope->$field, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table('dataset_import_scope_origins')->insert($chunk);
        }
    }
}
