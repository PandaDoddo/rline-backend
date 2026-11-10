<?php

namespace App\Modules\Diagnostics\Application;

use App\Modules\Diagnostics\Domain\VisualInspectionSchema;
use App\Modules\Diagnostics\Models\VisualInspectionStage;
use App\Modules\Equipment\Domain\ComponentTree;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Shared\Domain\ValueEquality;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Visual facts belong to the equipment aggregate; reads never create empty observations. */
final class EquipmentVisualInspections
{
    public function read(Equipment $equipment): array
    {
        $equipment->loadMissing(['components', 'inspections']);
        $diagnostics = $equipment->inspections->keyBy('id');
        $groups = [];
        $priority = ['НО' => 0, 'ТО-1' => 1, 'ТО-2' => 2, 'ЭПБ' => 3];
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->performed_on && isset($priority[$diagnostic->kind])) {
                $groups[$diagnostic->performed_on->format('Y-m-d')][] = $diagnostic;
            }
        }
        foreach ($groups as &$group) {
            usort($group, fn ($a, $b) => $priority[$b->kind] <=> $priority[$a->kind] ?: strcmp($a->id, $b->id));
        }
        unset($group);
        $stored = [];
        foreach (VisualInspectionStage::where('equipment_id', $equipment->id)->with('observations')->get() as $stage) {
            $sourceDate = $stage->reference_date?->format('Y-m-d') ?? $diagnostics->get($stage->inspection_id)?->performed_on?->format('Y-m-d');
            if (! $sourceDate || isset($stored[$sourceDate])) {
                $this->invalid('stages', 'Сохранённые этапы ВИК требуют разных дат выполненных диагностик.');
            }
            $stored[$sourceDate] = $stage;
        }
        $dates = array_unique([...array_keys($groups), ...array_keys($stored)]);
        sort($dates);
        $stages = [];
        foreach ($dates as $sourceDate) {
            $stage = $stored[$sourceDate] ?? null;
            $diagnostic = $groups[$sourceDate][0] ?? null;
            $stages[] = ['id' => $stage?->id ?? Uuid::uuid5(Uuid::NAMESPACE_URL, 'rline:visual-stage:'.$equipment->id.':'.$diagnostic->id)->toString(),
                'inspection_id' => $stage ? $stage->inspection_id : $diagnostic->id,
                'reference_date' => $stage?->reference_date?->format('Y-m-d'), 'source_date' => $sourceDate,
                'diagnostic_on' => $diagnostic ? $sourceDate : null, 'diagnostic_kind' => $diagnostic?->kind,
                'origin' => $stage && $stage->inspection_id === null ? 'manual' : 'inspection', 'persisted' => $stage !== null,
                'measured_on' => $stage?->measured_on?->format('Y-m-d'), 'verified' => $stage?->verified_at !== null,
                'verified_by' => $stage?->verified_by, 'verified_at' => $stage?->verified_at?->toISOString(),
                'observations' => $stage?->observations->map(fn ($observation) => ['component_id' => $observation->component_id, 'values' => $observation->publicValues()])->values()->all() ?? []];
        }
        $overrides = DB::connection('tenant')->table('equipment_visual_insulation_overrides')->where('equipment_id', $equipment->id)->orderBy('component_id')->get()
            ->map(fn ($row) => ['component_id' => $row->component_id, 'is_insulated' => (bool) $row->is_insulated])->all();

        return ['schema_version' => VisualInspectionSchema::VERSION, 'stages' => $stages, 'insulation_overrides' => $overrides, 'current_element_ids' => array_keys($this->elementIds($equipment))];
    }

    public function apply(Equipment $equipment, array $input, int $actor): void
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new \LogicException('Visual inspections require an equipment aggregate transaction.');
        }
        abort_unless($equipment->status === 'active', 422, 'Восстановите оборудование из архива перед правкой ВИК.');
        abort_unless($input['schema_version'] === VisualInspectionSchema::VERSION, 409, 'Схема ВИК изменилась. Обновите карточку.');
        $equipment->loadMissing(['components', 'inspections']);
        $components = $equipment->components->keyBy('id');
        $elements = $this->elementIds($equipment);
        $tree = new ComponentTree($equipment->components->toArray());
        $diagnostics = $equipment->inspections->keyBy('id');
        $stages = VisualInspectionStage::where('equipment_id', $equipment->id)->with('observations')->lockForUpdate()->get()->keyBy('id');
        $foreign = $db->table('equipment_visual_stages')->whereIn('id', array_column($input['stages'] ?? [], 'id'))->where('equipment_id', '<>', $equipment->id)->exists();
        if ($foreign) {
            $this->invalid('stages', 'Этап ВИК принадлежит другой ЕО.');
        }
        $dates = [];
        foreach ($stages as $stage) {
            $date = $stage->reference_date?->format('Y-m-d') ?? $diagnostics->get($stage->inspection_id)?->performed_on?->format('Y-m-d');
            if ($date) {
                $dates[$date] = $stage->id;
            }
        }
        $deletes = $input['delete_stage_ids'] ?? [];
        if (array_intersect($deletes, array_column($input['stages'] ?? [], 'id'))) {
            $this->invalid('stages', 'Нельзя одновременно изменить и удалить один этап.');
        }
        foreach ($deletes as $id) {
            $stage = $stages->get($id);
            if (! $stage || $stage->verified_at !== null) {
                $this->invalid('delete_stage_ids', 'Очистить или удалить можно только непроверенный этап этой ЕО.');
            }
            $db->table('equipment_visual_observations')->where('stage_id', $id)->delete();
            $stage->delete();
            foreach ($dates as $date => $owner) {
                if ($owner === $id) {
                    unset($dates[$date]);
                }
            }
        }
        foreach ($input['stages'] ?? [] as $index => $entry) {
            $path = 'stages.'.$index;
            $stage = $stages->get($entry['id']);
            if ($stage) {
                foreach (['inspection_id', 'reference_date'] as $key) {
                    $old = $key === 'reference_date' ? $stage->reference_date?->format('Y-m-d') : $stage->inspection_id;
                    if (array_key_exists($key, $entry) && $entry[$key] !== $old) {
                        $this->invalid($path.'.'.$key, 'Источник существующего этапа ВИК неизменен.');
                    }
                }
            } else {
                $inspection = isset($entry['inspection_id']) ? $diagnostics->get($entry['inspection_id']) : null;
                if (isset($entry['inspection_id']) && (! $inspection || ! $inspection->performed_on || ! in_array($inspection->kind, VisualInspectionSchema::KINDS, true))) {
                    $this->invalid($path.'.inspection_id', 'Выберите выполненные ТО-1, ТО-2, ЭПБ или НО этой ЕО.');
                }
                $date = $inspection?->performed_on?->format('Y-m-d') ?? ($entry['reference_date'] ?? null);
                if (! $date || $inspection && ($entry['reference_date'] ?? null) !== null) {
                    $this->invalid($path, 'Для внепланового ВИК укажите исходную дату; для диагностики отдельная исходная дата не задаётся.');
                }
                if (isset($dates[$date])) {
                    $this->invalid($path, 'На эту дату уже есть этап ВИК. Обновите карточку и измените его.');
                }
                $dates[$date] = $entry['id'];
                $stage = (new VisualInspectionStage)->forceFill(['id' => $entry['id'], 'equipment_id' => $equipment->id, 'inspection_id' => $inspection?->id,
                    'reference_date' => $inspection ? null : $date, 'created_by' => $actor, 'updated_by' => $actor]);
                $stage->setRelation('observations', collect());
            }
            $measured = array_key_exists('measured_on', $entry) ? $entry['measured_on'] : $stage->measured_on?->format('Y-m-d');
            $verified = $entry['verified'] ?? ($stage->verified_at !== null);
            if ($verified && ! $measured) {
                $this->invalid($path.'.measured_on', 'Для проверки этапа укажите фактическую дату ВИК.');
            }
            $oldRows = $stage->observations->keyBy('component_id');
            $reset = $entry['reset_components'] ?? [];
            $ids = array_column($entry['observations'] ?? [], 'component_id');
            if (count($ids) !== count(array_unique($ids)) || count($reset) !== count(array_unique($reset)) || array_intersect($ids, $reset)) {
                $this->invalid($path.'.observations', 'Элемент не должен повторяться или одновременно заполняться и сбрасываться.');
            }
            foreach ([...$ids, ...$reset] as $id) {
                if (! $components->has($id) || ! isset($elements[$id]) && ! $oldRows->has($id)) {
                    $this->invalid($path.'.observations', 'Наблюдение должно принадлежать физическому элементу этой ЕО.');
                }
            }
            foreach ($reset as $id) {
                if ($oldRows->has($id) && ! $tree->active($id)) {
                    $this->invalid($path.'.reset_components', 'Восстановите демонтированный элемент перед сбросом его ВИК.');
                }
            }
            $pending = [];
            foreach ($entry['observations'] ?? [] as $rowIndex => $observation) {
                $id = $observation['component_id'];
                $oldRow = $oldRows->get($id);
                $oldValues = $oldRow?->publicValues() ?? array_fill_keys(array_keys(VisualInspectionSchema::fields()), null);
                $values = array_replace($oldValues, $observation['values']);
                if (array_key_exists('corrosion_present', $observation['values']) && $values['corrosion_present'] !== true) {
                    foreach (VisualInspectionSchema::CORROSION_FIELDS as $key) {
                        if (($observation['values'][$key] ?? null) !== null) {
                            $this->invalid($path.'.observations.'.$rowIndex.'.values.'.$key, 'Параметры коррозии вводятся только при наличии коррозии.');
                        }
                        $values[$key] = null;
                    }
                }
                if ($values['corrosion_present'] !== true && array_filter(array_intersect_key($values, array_flip(VisualInspectionSchema::CORROSION_FIELDS)), fn ($value) => $value !== null)) {
                    $this->invalid($path.'.observations.'.$rowIndex, 'Сначала укажите наличие коррозии.');
                }
                if ($values['affected_area'] !== null && ($values['area_unit'] === null || $values['area_unit'] === 'percent' && $values['affected_area'] > 100)) {
                    $this->invalid($path.'.observations.'.$rowIndex, 'Для площади выберите единицу; процент не может превышать 100.');
                }
                if (! ValueEquality::same($oldValues, $values)) {
                    if (! $tree->active($id)) {
                        $this->invalid($path.'.observations.'.$rowIndex, 'Восстановите демонтированный элемент перед правкой ВИК.');
                    }
                    $pending[] = ['id' => $oldRow?->id ?? (string) Str::uuid(), 'stage_id' => $stage->id, 'equipment_id' => $equipment->id, 'component_id' => $id,
                        ...$values, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()];
                }
            }
            $changed = $measured !== $stage->measured_on?->format('Y-m-d') || $pending || $oldRows->keys()->intersect($reset)->isNotEmpty();
            if ($stage->verified_at && $changed && $verified && ! ($entry['unlock'] ?? false)) {
                $this->invalid($path, 'Сначала снимите проверку этапа ВИК.');
            }
            if ($stage->verified_at && (! $verified || ($entry['unlock'] ?? false))) {
                $stage->forceFill(['verified_at' => null, 'verified_by' => null])->save();
            }
            $stage->forceFill(['measured_on' => $measured, 'updated_by' => $actor])->save();
            $db->table('equipment_visual_observations')->where('stage_id', $stage->id)->whereIn('component_id', $reset)->delete();
            foreach (array_chunk($pending, 200) as $chunk) {
                $db->table('equipment_visual_observations')->upsert($chunk, ['stage_id', 'component_id'], [...array_keys(VisualInspectionSchema::fields()), 'updated_by', 'updated_at']);
            }
            if ($verified && $stage->verified_at === null) {
                $stage->forceFill(['verified_by' => $actor, 'verified_at' => now()])->save();
            }
        }
        $oldOverrides = $db->table('equipment_visual_insulation_overrides')->where('equipment_id', $equipment->id)->pluck('is_insulated', 'component_id');
        $overrideWrites = [];
        foreach ($input['insulation_overrides'] ?? [] as $entry) {
            $id = $entry['component_id'];
            if (! $components->has($id) || ! isset($elements[$id])) {
                $this->invalid('insulation_overrides', 'Изоляция задаётся для физического элемента этой ЕО.');
            }
            if (! $tree->active($id) && ($entry['is_insulated'] ?? null) !== $oldOverrides->get($id)) {
                $this->invalid('insulation_overrides', 'Восстановите демонтированный элемент перед правкой изоляции.');
            }
            if ($entry['is_insulated'] === null) {
                $db->table('equipment_visual_insulation_overrides')->where('component_id', $id)->delete();
            } else {
                $overrideWrites[] = ['component_id' => $id, 'equipment_id' => $equipment->id, 'is_insulated' => $entry['is_insulated'], 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($overrideWrites, 200) as $chunk) {
            $db->table('equipment_visual_insulation_overrides')->upsert($chunk, ['component_id'], ['is_insulated', 'updated_by', 'updated_at']);
        }
    }

    private function elementIds(Equipment $equipment): array
    {
        $parents = array_fill_keys($equipment->components->pluck('parent_id')->filter()->all(), true);
        $ids = $equipment->components->filter(fn ($row) => ! isset($parents[$row->id]))->pluck('id')->all();
        $measured = DB::connection('tenant')->table('equipment_cmls')->where('equipment_id', $equipment->id)->distinct()->pluck('component_id')->all();

        return array_fill_keys([...$ids, ...$measured], true);
    }

    private function invalid(string $path, string $message): never
    {
        throw ValidationException::withMessages(['visual_inspections.'.$path => $message]);
    }
}
