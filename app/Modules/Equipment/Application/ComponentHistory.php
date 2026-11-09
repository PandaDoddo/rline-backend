<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Models\ComponentHistoryEntry;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Equipment\Models\EquipmentComponent;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ComponentHistory
{
    public const FIELDS = ['kind', 'occurred_on', 'actor_name', 'description', 'source_reference'];

    public const KINDS = ['added', 'removed', 'restored', 'replaced', 'note', 'legacy'];

    public function count(EquipmentComponent $component): int
    {
        if ($component->relationLoaded('cardProjection')) {
            return (int) $component->getRelation('cardProjection')->getAttribute('history_count');
        }
        if (! array_key_exists('history_entries_count', $component->getAttributes())) {
            throw new \LogicException('History counts must be batch-loaded.');
        }

        return (int) $component->getAttribute('history_entries_count');
    }

    public function apply(Equipment $equipment, Collection $components, array $items, int $actor): void
    {
        $patches = collect($items)->filter(fn ($item) => ! empty($item['history']));
        $ids = $patches->flatMap(fn ($item) => array_column($item['history'], 'id'))->all();
        if (! $ids) {
            return;
        }
        $existing = ComponentHistoryEntry::whereIn('id', $ids)->get()->keyBy('id');
        foreach ($patches as $item) {
            if (! $components->has($item['id'])) {
                throw ValidationException::withMessages(['structure' => 'История должна принадлежать компоненту этой ЕО.']);
            }
            foreach ($item['history'] as $input) {
                $old = $existing->get($input['id']);
                $values = [];
                foreach (self::FIELDS as $field) {
                    $values[$field] = $input[$field] ?? null;
                }
                if ($old) {
                    $previous = $old->only(self::FIELDS);
                    $previous['occurred_on'] = $old->occurred_on?->toDateString();
                    if ($old->equipment_id !== $equipment->id || $old->component_id !== $item['id'] || $previous !== $values) {
                        throw ValidationException::withMessages(['structure' => 'Сохранённое событие истории неизменяемо; добавьте уточняющую запись.']);
                    }

                    continue;
                }
                $entry = new ComponentHistoryEntry;
                $entry->fill($values)->forceFill(['id' => $input['id'], 'equipment_id' => $equipment->id, 'component_id' => $item['id'], 'recorded_by' => $actor, 'recorded_at' => now()])->save();
            }
        }
    }

    public static function rules(): array
    {
        return ['structure.*.history' => 'sometimes|array|list|max:1000', 'structure.*.history.*' => 'required|array:id,'.implode(',', self::FIELDS),
            'structure.*.history.*.id' => 'required|uuid|distinct', 'structure.*.history.*.kind' => 'required|in:'.implode(',', self::KINDS),
            'structure.*.history.*.occurred_on' => 'sometimes|nullable|date_format:Y-m-d', 'structure.*.history.*.actor_name' => 'sometimes|nullable|string|max:255',
            'structure.*.history.*.description' => 'required|string|min:1|max:10000', 'structure.*.history.*.source_reference' => 'sometimes|nullable|string|max:1000'];
    }
}
