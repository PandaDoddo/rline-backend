<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Domain\CmlCode;
use App\Modules\Equipment\Models\Equipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EquipmentThickness
{
    public function ensureStages(Equipment $equipment): void
    {
        $db = DB::connection('tenant');
        $existing = $db->table('equipment_thickness_stages')->where('equipment_id', $equipment->id)->pluck('inspection_id')->all();
        foreach ($equipment->inspections()->get() as $inspection) {
            if (in_array($inspection->id, $existing, true) || ! $inspection->is_primary && (! $inspection->performed_on || ! in_array($inspection->kind, ['ТО-1', 'ТО-2', 'ЭПБ', 'УЗТ'], true))) {
                continue;
            }
            $db->table('equipment_thickness_stages')->insert(['id' => (string) Str::uuid(), 'equipment_id' => $equipment->id, 'inspection_id' => $inspection->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function read(Equipment $equipment): array
    {
        $db = DB::connection('tenant');
        $cmls = $db->table('equipment_cmls')->where('equipment_id', $equipment->id)->orderBy('code')->get()->map(fn ($row) => ['id' => $row->id, 'component_id' => $row->component_id, 'code' => $row->code, 'point_count' => $row->point_count === null ? null : (int) $row->point_count, 'notes' => $row->notes])->all();
        $stages = $db->table('equipment_thickness_stages')->where('equipment_id', $equipment->id)->orderBy('measured_on')->orderBy('id')->get();
        $measurements = $db->table('equipment_thickness_measurements')->whereIn('stage_id', $stages->pluck('id'))->get()->groupBy('stage_id');

        return ['cmls' => $cmls, 'stages' => $stages->map(fn ($row) => ['id' => $row->id, 'inspection_id' => $row->inspection_id, 'origin' => $row->inspection_id === null ? 'manual' : 'inspection', 'measured_on' => $row->measured_on,
            'verified' => $row->verified_at !== null, 'verified_by' => $row->verified_by, 'verified_at' => $row->verified_at,
            'measurements' => collect($measurements->get($row->id, []))->map(fn ($value) => ['cml_id' => $value->cml_id, 'points' => json_decode($value->points, true, flags: JSON_THROW_ON_ERROR), 'point_origins' => json_decode($value->point_origins, true, flags: JSON_THROW_ON_ERROR)])->all()])->all()];
    }

    public function apply(Equipment $equipment, array $input, int $actor): void
    {
        $db = DB::connection('tenant');
        $components = $equipment->components()->get()->keyBy('id');
        $cmls = $db->table('equipment_cmls')->where('equipment_id', $equipment->id)->get()->keyBy('id');
        foreach ($input['cmls'] ?? [] as $item) {
            $component = $components->get($item['component_id']);
            if (! $component) {
                throw ValidationException::withMessages(['thickness' => 'CML должен принадлежать компоненту этой ЕО.']);
            }
            $row = $cmls->get($item['id']);
            if ($row && $row->component_id !== $component->id || ! $row && $db->table('equipment_cmls')->where('id', $item['id'])->exists()) {
                throw ValidationException::withMessages(['thickness' => 'Принадлежность CML неизменна.']);
            }
            if ($row && ($row->point_count === null ? null : (int) $row->point_count) !== $item['point_count'] && $db->table('equipment_thickness_values')->where('cml_id', $row->id)->exists()) {
                $exceeds = $item['point_count'] === null || $db->table('equipment_thickness_points as p')->join('equipment_thickness_values as m', 'm.id', '=', 'p.measurement_id')->where('m.cml_id', $row->id)->where('p.point_number', '>', $item['point_count'])->exists();
                if ($row->point_count !== null || $exceeds) {
                    throw ValidationException::withMessages(['thickness' => 'Можно уточнить только неизвестное количество точек, сохранив все измеренные координаты. Изменение известной схемы требует нового CML.']);
                }
            }
            if ($row && isset($item['code']) && $item['code'] !== $row->code) {
                throw ValidationException::withMessages(['thickness' => 'Код существующего CML неизменен.']);
            }
            $values = ['point_count' => $item['point_count'], 'notes' => $item['notes'] ?? null, 'updated_at' => now()];
            if (! $row) {
                try {
                    $allocated = (new CmlCode)->allocate($item['code'] ?? null, $component->code, $component->next_cml_number);
                } catch (\InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['thickness' => $exception->getMessage()]);
                }
                $code = $allocated['code'];
                $component->next_cml_number = $allocated['next'];
                $values += ['id' => $item['id'], 'equipment_id' => $equipment->id, 'component_id' => $component->id, 'code' => $code, 'created_at' => now()];
                $db->table('equipment_cmls')->insert($values);
                $component->save();
            } else {
                $db->table('equipment_cmls')->where('id', $row->id)->update($values);
            }
            $cmls->put($item['id'], (object) ($values + ['id' => $item['id'], 'component_id' => $component->id]));
        }
        $stages = $db->table('equipment_thickness_stages')->where('equipment_id', $equipment->id)->get()->keyBy('id');
        foreach ($input['stages'] ?? [] as $item) {
            $stage = $stages->get($item['id']);
            if (! $stage) {
                if (($item['origin'] ?? null) !== 'manual' || empty($item['measured_on']) || $db->table('equipment_thickness_stages')->where('id', $item['id'])->exists()) {
                    throw ValidationException::withMessages(['thickness' => 'Для нового внепланового этапа УЗТ укажите источник manual и фактическую дату. Идентификатор должен быть свободен.']);
                }
                $db->table('equipment_thickness_stages')->insert(['id' => $item['id'], 'equipment_id' => $equipment->id, 'inspection_id' => null, 'created_at' => now(), 'updated_at' => now()]);
                $stage = $db->table('equipment_thickness_stages')->where('id', $item['id'])->first();
            }
            if (isset($item['origin']) && $item['origin'] !== ($stage->inspection_id === null ? 'manual' : 'inspection')) {
                throw ValidationException::withMessages(['thickness' => 'Источник этапа УЗТ неизменен.']);
            }
            if ($stage->inspection_id === null && empty($item['measured_on'])) {
                throw ValidationException::withMessages(['thickness' => 'Для внепланового этапа УЗТ обязательна фактическая дата.']);
            }
            $old = $db->table('equipment_thickness_measurements')->where('stage_id', $stage->id)->get()->keyBy('cml_id');
            $changed = $stage->measured_on !== ($item['measured_on'] ?? null);
            $pending = [];
            $ids = array_column($item['measurements'] ?? [], 'cml_id');
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['thickness' => 'CML не должен повторяться в одном этапе.']);
            }
            foreach ($item['measurements'] ?? [] as $measurement) {
                $cml = $cmls->get($measurement['cml_id']);
                if (! $cml || $cml->point_count !== null && count($measurement['points']) !== (int) $cml->point_count) {
                    throw ValidationException::withMessages(['thickness' => 'CML и количество точек должны соответствовать этому оборудованию.']);
                }
                $points = array_map(fn ($value) => $value === null ? null : (float) $value, $measurement['points']);
                $previous = $old->get($cml->id);
                if (! $previous || array_map(fn ($value) => $value === null ? null : (float) $value, json_decode($previous->points, true)) !== $points) {
                    $changed = true;
                    $pending[$cml->id] = $points;
                }
            }
            if ($stage->verified_at !== null && $changed) {
                throw ValidationException::withMessages(['thickness' => 'Проверенный этап защищён. Сначала снимите проверку отдельным сохранением.']);
            }
            if ($item['verified'] && ! ($item['measured_on'] ?? null)) {
                throw ValidationException::withMessages(['thickness' => 'Для проверки этапа укажите фактическую дату толщинометрии.']);
            }
            foreach (array_chunk($pending, 500, true) as $batch) {
                $timestamp = now();
                $records = [];
                foreach (array_keys($batch) as $cmlId) {
                    $records[] = ['equipment_id' => $equipment->id, 'stage_id' => $stage->id, 'cml_id' => $cmlId, 'created_at' => $timestamp, 'updated_at' => $timestamp];
                }
                $db->table('equipment_thickness_values')->upsert($records, ['stage_id', 'cml_id'], ['updated_at']);
                $identities = $db->table('equipment_thickness_values')->where('stage_id', $stage->id)->whereIn('cml_id', array_keys($batch))->pluck('id', 'cml_id');
                $points = [];
                foreach ($batch as $cmlId => $values) {
                    foreach ($values as $index => $value) {
                        $points[] = ['measurement_id' => $identities[$cmlId], 'point_number' => $index + 1, 'thickness_mm' => $value];
                    }
                }
                foreach (array_chunk($points, 1000) as $chunk) {
                    $db->table('equipment_thickness_points')->upsert($chunk, ['measurement_id', 'point_number'], ['thickness_mm']);
                }
            }
            $db->table('equipment_thickness_stages')->where('id', $stage->id)->update(['measured_on' => $item['measured_on'] ?? null,
                'verified_by' => $item['verified'] ? ($stage->verified_by ?? $actor) : null, 'verified_at' => $item['verified'] ? ($stage->verified_at ?? now()) : null, 'updated_at' => now()]);
        }
    }

    public static function rules(): array
    {
        return ['thickness' => 'sometimes|array:cmls,stages', 'thickness.cmls' => 'sometimes|array|max:500', 'thickness.cmls.*' => 'required|array:id,code,component_id,point_count,notes',
            'thickness.cmls.*.code' => 'sometimes|string|max:60',
            'thickness.cmls.*.id' => 'required|uuid|distinct', 'thickness.cmls.*.component_id' => 'required|uuid', 'thickness.cmls.*.point_count' => 'present|nullable|integer|min:1|max:100', 'thickness.cmls.*.notes' => 'nullable|string|max:10000',
            'thickness.stages' => 'sometimes|array|max:500', 'thickness.stages.*' => 'required|array:id,origin,measured_on,verified,measurements', 'thickness.stages.*.id' => 'required|uuid|distinct',
            'thickness.stages.*.origin' => 'sometimes|required|in:manual,inspection',
            'thickness.stages.*.measured_on' => 'nullable|date_format:Y-m-d', 'thickness.stages.*.verified' => 'required|boolean',
            'thickness.stages.*.measurements' => 'sometimes|array|max:500', 'thickness.stages.*.measurements.*' => 'required|array:cml_id,points',
            'thickness.stages.*.measurements.*.cml_id' => 'required|uuid', 'thickness.stages.*.measurements.*.points' => 'required|array|list|min:1|max:100',
            'thickness.stages.*.measurements.*.points.*' => 'nullable|numeric|min:0|max:100000'];
    }
}
