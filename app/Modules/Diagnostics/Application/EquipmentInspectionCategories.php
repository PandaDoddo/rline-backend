<?php

namespace App\Modules\Diagnostics\Application;

use App\Modules\Diagnostics\Domain\InspectionCategorySchema;
use App\Modules\Diagnostics\Models\InspectionCategoryStage;
use App\Modules\Equipment\Domain\ComponentTree;
use App\Modules\Equipment\Models\Equipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Saved inspection evidence. Mechanism applicability and calculated categories are read projections. */
final class EquipmentInspectionCategories
{
    public static function kinds(string $group): array
    {
        return match ($group) {
            'thin' => ['ТО-1', 'ТО-2', 'ЭПБ', 'УЗТ'],
            'ext', 'cui', 'cl' => ['ТО-1', 'ТО-2', 'ЭПБ', 'НО'],
            default => ['ТО-1', 'ТО-2', 'ЭПБ'],
        };
    }

    public function read(Equipment $equipment): array
    {
        $equipment->loadMissing('inspections');
        $diagnostics = $equipment->inspections->keyBy('id');
        $stored = InspectionCategoryStage::where('equipment_id', $equipment->id)->with('observations')->get();
        $priority = ['УЗТ' => 0, 'НО' => 1, 'ТО-1' => 2, 'ТО-2' => 3, 'ЭПБ' => 4];
        $manualThickness = DB::connection('tenant')->table('equipment_thickness_stages')->where('equipment_id', $equipment->id)->whereNull('inspection_id')->whereNotNull('measured_on')->pluck('measured_on')->all();
        $manualVisual = DB::connection('tenant')->table('equipment_visual_stages')->where('equipment_id', $equipment->id)->whereNull('inspection_id')->pluck('reference_date')->all();
        $result = [];
        foreach (InspectionCategorySchema::groups() as $group => $_) {
            $calendar = [];
            foreach ($diagnostics as $diagnostic) {
                if (! $diagnostic->performed_on || ! in_array($diagnostic->kind, self::kinds($group), true)) {
                    continue;
                }
                $date = $diagnostic->performed_on->format('Y-m-d');
                if (! isset($calendar[$date]) || $priority[$diagnostic->kind] > $priority[$calendar[$date]->kind] || $diagnostic->kind === $calendar[$date]->kind && strcmp($diagnostic->id, $calendar[$date]->id) < 0) {
                    $calendar[$date] = $diagnostic;
                }
            }
            $byDate = [];
            foreach ($stored->where('group_code', $group) as $stage) {
                $date = $stage->reference_date?->format('Y-m-d') ?? $diagnostics->get($stage->inspection_id)?->performed_on?->format('Y-m-d');
                if (! $date || isset($byDate[$date])) {
                    $this->invalid('stages', 'Сохранённые категории требуют разных дат внутри группы.');
                }
                $byDate[$date] = $stage;
            }
            $extra = $group === 'thin' ? $manualThickness : (in_array($group, ['ext', 'cui', 'cl'], true) ? $manualVisual : []);
            $dates = array_unique([...array_keys($calendar), ...array_keys($byDate), ...$extra]);
            sort($dates);
            foreach ($dates as $date) {
                $stage = $byDate[$date] ?? null;
                $diagnostic = $calendar[$date] ?? null;
                $result[] = ['id' => $stage?->id ?? Uuid::uuid5(Uuid::NAMESPACE_URL, 'rline:inspection-category:'.$equipment->id.':'.$group.':'.($diagnostic?->id ?? 'manual:'.$date))->toString(),
                    'group_code' => $group, 'inspection_id' => $stage ? $stage->inspection_id : $diagnostic?->id, 'reference_date' => $stage ? $stage->reference_date?->format('Y-m-d') : ($diagnostic ? null : $date),
                    'source_date' => $date, 'diagnostic_kind' => $diagnostic?->kind, 'origin' => ($stage ? $stage->inspection_id === null : $diagnostic === null) ? 'manual' : 'inspection', 'persisted' => $stage !== null,
                    'observations' => $stage?->observations->map(fn ($row) => ['component_id' => $row->component_id, 'category' => $row->category, 'cracks_present' => $row->cracks_present])->values()->all() ?? []];
            }
        }

        return ['schema_version' => InspectionCategorySchema::VERSION, 'stages' => $result];
    }

    public function apply(Equipment $equipment, array $input, int $actor): void
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new \LogicException('Inspection categories require an equipment aggregate transaction.');
        }
        abort_unless($equipment->status === 'active', 422, 'Восстановите оборудование из архива перед правкой категорий.');
        abort_unless($input['schema_version'] === InspectionCategorySchema::VERSION, 409, 'Схема категорий инспекции изменилась. Обновите карточку.');
        $equipment->loadMissing(['components', 'inspections']);
        $components = $equipment->components->keyBy('id');
        $parents = array_fill_keys($equipment->components->pluck('parent_id')->filter()->all(), true);
        $measured = array_fill_keys($db->table('equipment_cmls')->where('equipment_id', $equipment->id)->pluck('component_id')->all(), true);
        $tree = new ComponentTree($equipment->components->toArray());
        $diagnostics = $equipment->inspections->keyBy('id');
        $stages = InspectionCategoryStage::where('equipment_id', $equipment->id)->with('observations')->lockForUpdate()->get()->keyBy('id');
        if ($db->table('equipment_inspection_category_stages')->whereIn('id', array_column($input['stages'] ?? [], 'id'))->where('equipment_id', '<>', $equipment->id)->exists()) {
            $this->invalid('stages', 'Этап категорий принадлежит другой ЕО.');
        }
        $deletes = $input['delete_stage_ids'] ?? [];
        if (array_intersect($deletes, array_column($input['stages'] ?? [], 'id'))) {
            $this->invalid('stages', 'Нельзя одновременно изменить и удалить этап.');
        }
        foreach ($deletes as $id) {
            if (! $stages->has($id)) {
                $this->invalid('delete_stage_ids', 'Сброс возможен только для сохранённого этапа этой ЕО.');
            }
            foreach ($stages[$id]->observations as $observation) {
                if (! $tree->active($observation->component_id)) {
                    $this->invalid('delete_stage_ids', 'Восстановите демонтированные элементы перед сбросом их категорий.');
                }
            }
            $db->table('equipment_inspection_category_observations')->where('stage_id', $id)->delete();
            $stages[$id]->delete();
            $stages->forget($id);
        }
        $dates = [];
        foreach ($stages as $stage) {
            $dates[$stage->group_code.'|'.($stage->reference_date?->format('Y-m-d') ?? $diagnostics->get($stage->inspection_id)?->performed_on?->format('Y-m-d'))] = $stage->id;
        }
        foreach ($input['stages'] ?? [] as $index => $entry) {
            $path = 'stages.'.$index;
            $stage = $stages->get($entry['id']);
            if ($stage) {
                foreach (['group_code', 'inspection_id', 'reference_date'] as $key) {
                    $previous = $key === 'reference_date' ? $stage->reference_date?->format('Y-m-d') : $stage->$key;
                    if (array_key_exists($key, $entry) && $entry[$key] !== $previous) {
                        $this->invalid($path.'.'.$key, 'Источник и группа этапа неизменны.');
                    }
                }
            } else {
                $inspection = isset($entry['inspection_id']) ? $diagnostics->get($entry['inspection_id']) : null;
                if (isset($entry['inspection_id']) && (! $inspection || ! $inspection->performed_on || ! in_array($inspection->kind, self::kinds($entry['group_code']), true))) {
                    $this->invalid($path.'.inspection_id', 'Выберите выполненную диагностику этой ЕО для данной группы.');
                }
                $date = $inspection?->performed_on?->format('Y-m-d') ?? ($entry['reference_date'] ?? null);
                if (! $date || $inspection && ($entry['reference_date'] ?? null) !== null) {
                    $this->invalid($path, 'Укажите один источник этапа: выполненную диагностику или внеплановую дату.');
                }
                $key = $entry['group_code'].'|'.$date;
                if (isset($dates[$key])) {
                    $this->invalid($path, 'На эту дату в группе уже есть этап; обновите карточку.');
                }
                $dates[$key] = $entry['id'];
                $stage = (new InspectionCategoryStage)->forceFill(['id' => $entry['id'], 'equipment_id' => $equipment->id, 'group_code' => $entry['group_code'], 'inspection_id' => $inspection?->id, 'reference_date' => $inspection ? null : $date, 'created_by' => $actor, 'updated_by' => $actor]);
                $stage->setRelation('observations', collect());
            }
            $old = $stage->observations->keyBy('component_id');
            $reset = $entry['reset_components'] ?? [];
            $ids = array_column($entry['observations'] ?? [], 'component_id');
            if (count($ids) !== count(array_unique($ids)) || count($reset) !== count(array_unique($reset)) || array_intersect($ids, $reset)) {
                $this->invalid($path.'.observations', 'Элемент не должен повторяться или одновременно заполняться и сбрасываться.');
            }
            foreach ([...$ids, ...$reset] as $id) {
                if (! $components->has($id) || isset($parents[$id]) && ! isset($measured[$id]) && ! $old->has($id)) {
                    $this->invalid($path.'.observations', 'Категория относится к физическому элементу этой ЕО.');
                }
                if (! $tree->active($id)) {
                    $this->invalid($path.'.observations', 'Восстановите демонтированный элемент перед правкой категории.');
                }
            }
            $pending = [];
            foreach ($entry['observations'] ?? [] as $row) {
                $previous = $old->get($row['component_id']);
                $category = array_key_exists('category', $row) ? $row['category'] : $previous?->category;
                $cracks = array_key_exists('cracks_present', $row) ? $row['cracks_present'] : $previous?->cracks_present;
                if (! InspectionCategorySchema::groups()[$entry['group_code']]['cracks'] && $cracks !== null) {
                    $this->invalid($path.'.observations', 'Отметки трещин относятся только к группам растрескивания.');
                }
                if ($category === null && $cracks === null) {
                    if ($previous) {
                        $reset[] = $row['component_id'];
                    }

                    continue;
                }
                if ($previous && $category === $previous->category && $cracks === $previous->cracks_present) {
                    continue;
                }
                $pending[] = ['id' => $previous?->id ?? (string) Str::uuid(), 'equipment_id' => $equipment->id, 'stage_id' => $stage->id, 'component_id' => $row['component_id'], 'category' => $category, 'cracks_present' => $cracks, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()];
            }
            $stage->forceFill(['updated_by' => $actor])->save();
            if ($reset) {
                $db->table('equipment_inspection_category_observations')->where('stage_id', $stage->id)->whereIn('component_id', $reset)->delete();
            }
            foreach (array_chunk($pending, 200) as $chunk) {
                $db->table('equipment_inspection_category_observations')->upsert($chunk, ['id'], ['category', 'cracks_present', 'updated_by', 'updated_at']);
            }
        }
    }

    /** Validate the final calendar before SQL, allowing only explicit same-command resets. */
    public function guardDiagnostics(Equipment $equipment, array $changes, array $resets = []): void
    {
        $equipment->loadMissing('inspections');
        $diagnostics = [];
        foreach ($equipment->inspections as $inspection) {
            $diagnostics[$inspection->id] = ['kind' => $inspection->kind, 'performed_on' => $inspection->performed_on?->format('Y-m-d')];
        }
        foreach ($changes as $change) {
            $diagnostics[$change['id']] = array_replace($diagnostics[$change['id']] ?? [], $change);
        }
        $dates = [];
        foreach (InspectionCategoryStage::where('equipment_id', $equipment->id)->whereNotIn('id', $resets)->get() as $stage) {
            $source = $diagnostics[$stage->inspection_id] ?? null;
            $date = $stage->reference_date?->format('Y-m-d');
            if ($stage->inspection_id !== null) {
                if (! $source || ! ($source['performed_on'] ?? null) || ! in_array($source['kind'], self::kinds($stage->group_code), true)) {
                    $this->invalid('stages', 'Сначала явно сбросьте категории связанного этапа перед удалением даты выполненной диагностики.');
                }
                $date = $source['performed_on'];
            }
            $key = $stage->group_code.'|'.$date;
            if (isset($dates[$key])) {
                $this->invalid('stages', 'На новую дату в группе уже есть сохранённые категории. Их нельзя молча объединить.');
            }
            $dates[$key] = true;
        }
    }

    private function invalid(string $path, string $message): never
    {
        throw ValidationException::withMessages(['inspection_categories.'.$path => $message]);
    }
}
