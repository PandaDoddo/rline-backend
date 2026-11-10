<?php

namespace App\Modules\Rbi\Application;

use App\Modules\Equipment\Domain\ComponentTree;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Rbi\Models\ComponentRbiSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Mutable component facts; no calculated damage factors or implicit weld coefficient. */
final class EquipmentRbiSettings
{
    public const FIELDS = ['weld_efficiency', 'injection_point', 'dead_leg', 'confidence', 'notes'];

    public function read(Equipment $equipment): array
    {
        return ComponentRbiSettings::where('equipment_id', $equipment->id)->orderBy('component_id')->get()->map(fn ($model) => [
            'component_id' => $model->component_id, ...$this->values($model),
            'updated_by' => (int) $model->updated_by, 'updated_at' => $model->updated_at->toISOString(),
        ])->all();
    }

    public function apply(Equipment $equipment, array $rows, int $actor): void
    {
        if (DB::connection('tenant')->transactionLevel() === 0) {
            throw new LogicException('RBI settings require the equipment aggregate transaction.');
        }
        $components = array_column($equipment->components->toArray(), null, 'id');
        $tree = new ComponentTree(array_values($components));
        $stored = ComponentRbiSettings::where('equipment_id', $equipment->id)->get()->keyBy('component_id');
        foreach ($rows as $index => $entry) {
            $id = $entry['component_id'];
            if (! isset($components[$id])) {
                throw ValidationException::withMessages(["component_rbi.$index.component_id" => 'Компонент не принадлежит этой ЕО.']);
            }
            if ($components[$id]['parent_id'] !== null && (($entry['injection_point'] ?? null) !== null || ($entry['dead_leg'] ?? null) !== null)) {
                throw ValidationException::withMessages(["component_rbi.$index.component_id" => 'Признаки инжекции и застойного участка задаются у основного компонента, а не его сегмента.']);
            }
            $model = $stored->get($id) ?? new ComponentRbiSettings;
            $model->forceFill(['component_id' => $id, 'equipment_id' => $equipment->id, ...array_intersect_key($entry, array_flip(self::FIELDS))]);
            $present = array_filter($this->values($model), fn ($value) => $value !== null);
            if ($present && ! $tree->active($id)) {
                throw ValidationException::withMessages(["component_rbi.$index.component_id" => 'Для архивного компонента можно только сбросить сохранённые настройки RBI.']);
            }
            if (! $present) {
                if ($model->exists) {
                    $model->delete();
                }

                continue;
            }
            if (! $model->exists) {
                $model->forceFill(['created_by' => $actor]);
            }
            $model->forceFill(['updated_by' => $actor])->save();
        }
    }

    private function values(ComponentRbiSettings $model): array
    {
        return array_combine(self::FIELDS, array_map(fn ($field) => $model->getAttribute($field), self::FIELDS));
    }
}
