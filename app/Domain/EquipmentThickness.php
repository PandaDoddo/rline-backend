<?php

namespace App\Domain;

use App\Models\Equipment;
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
        $cmls = $db->table('equipment_cmls')->where('equipment_id', $equipment->id)->orderBy('code')->get()->map(fn ($row) => ['id' => $row->id, 'component_id' => $row->component_id, 'code' => $row->code, 'point_count' => (int) $row->point_count, 'notes' => $row->notes])->all();
        $stages = $db->table('equipment_thickness_stages')->where('equipment_id', $equipment->id)->orderBy('measured_on')->orderBy('id')->get();
        $measurements = $db->table('equipment_thickness_values')->whereIn('stage_id', $stages->pluck('id'))->get()->groupBy('stage_id');

        return ['cmls' => $cmls, 'stages' => $stages->map(fn ($row) => ['id' => $row->id, 'inspection_id' => $row->inspection_id, 'measured_on' => $row->measured_on,
            'verified' => $row->verified_at !== null, 'verified_by' => $row->verified_by, 'verified_at' => $row->verified_at,
            'measurements' => collect($measurements->get($row->id, []))->map(fn ($value) => ['cml_id' => $value->cml_id, 'points' => json_decode($value->points, true, flags: JSON_THROW_ON_ERROR)])->all()])->all()];
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
            if ($row && (int) $row->point_count !== $item['point_count'] && $db->table('equipment_thickness_values')->where('cml_id', $row->id)->exists()) {
                throw ValidationException::withMessages(['thickness' => 'Нельзя менять число точек CML с сохранёнными замерами; создайте новый CML.']);
            }
            $values = ['point_count' => $item['point_count'], 'notes' => $item['notes'] ?? null, 'updated_at' => now()];
            if (! $row) {
                $number = $component->next_cml_number++;
                $code = 'CML-'.str_replace(['K-', '.S'], ['', '.'], $component->code).'.'.$number;
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
                throw ValidationException::withMessages(['thickness' => 'Этап УЗТ не принадлежит этой ЕО.']);
            }
            $old = $db->table('equipment_thickness_values')->where('stage_id', $stage->id)->get()->keyBy('cml_id');
            $changed = $stage->measured_on !== ($item['measured_on'] ?? null);
            $ids = array_column($item['measurements'] ?? [], 'cml_id');
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['thickness' => 'CML не должен повторяться в одном этапе.']);
            }
            foreach ($item['measurements'] ?? [] as $measurement) {
                $cml = $cmls->get($measurement['cml_id']);
                if (! $cml || count($measurement['points']) !== (int) $cml->point_count) {
                    throw ValidationException::withMessages(['thickness' => 'CML и количество точек должны соответствовать этому оборудованию.']);
                }
                $points = array_map(fn ($value) => $value === null ? null : (float) $value, $measurement['points']);
                $previous = $old->get($cml->id);
                if ($previous ? array_map(fn ($value) => $value === null ? null : (float) $value, json_decode($previous->points, true)) !== $points : count(array_filter($points, fn ($value) => $value !== null)) > 0) {
                    $changed = true;
                }
            }
            if ($stage->verified_at !== null && $changed) {
                throw ValidationException::withMessages(['thickness' => 'Проверенный этап защищён. Сначала снимите проверку отдельным сохранением.']);
            }
            if ($item['verified'] && ! ($item['measured_on'] ?? null)) {
                throw ValidationException::withMessages(['thickness' => 'Для проверки этапа укажите фактическую дату толщинометрии.']);
            }
            foreach ($item['measurements'] ?? [] as $measurement) {
                $db->table('equipment_thickness_values')->updateOrInsert(['stage_id' => $stage->id, 'cml_id' => $measurement['cml_id']],
                    ['points' => json_encode(array_map(fn ($value) => $value === null ? null : (float) $value, $measurement['points']), JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
            $db->table('equipment_thickness_stages')->where('id', $stage->id)->update(['measured_on' => $item['measured_on'] ?? null,
                'verified_by' => $item['verified'] ? ($stage->verified_by ?? $actor) : null, 'verified_at' => $item['verified'] ? ($stage->verified_at ?? now()) : null, 'updated_at' => now()]);
        }
    }

    public static function rules(): array
    {
        return ['thickness' => 'sometimes|array:cmls,stages', 'thickness.cmls' => 'sometimes|array|max:500', 'thickness.cmls.*' => 'required|array:id,component_id,point_count,notes',
            'thickness.cmls.*.id' => 'required|uuid|distinct', 'thickness.cmls.*.component_id' => 'required|uuid', 'thickness.cmls.*.point_count' => 'required|integer|min:1|max:100', 'thickness.cmls.*.notes' => 'nullable|string|max:10000',
            'thickness.stages' => 'sometimes|array|max:500', 'thickness.stages.*' => 'required|array:id,measured_on,verified,measurements', 'thickness.stages.*.id' => 'required|uuid|distinct',
            'thickness.stages.*.measured_on' => 'nullable|date_format:Y-m-d', 'thickness.stages.*.verified' => 'required|boolean',
            'thickness.stages.*.measurements' => 'sometimes|array|max:500', 'thickness.stages.*.measurements.*' => 'required|array:cml_id,points',
            'thickness.stages.*.measurements.*.cml_id' => 'required|uuid', 'thickness.stages.*.measurements.*.points' => 'required|array|list|min:1|max:100',
            'thickness.stages.*.measurements.*.points.*' => 'nullable|numeric|min:0|max:100000'];
    }
}
