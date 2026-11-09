<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Models\ComponentConnection;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Equipment\Models\EquipmentComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ComponentConnections
{
    public const FIELDS = ['status', 'position', 'side', 'joining_method', 'collar_present', 'thread_kind', 'thread_size', 'notes', 'source_reference'];

    public function read(EquipmentComponent $component): array
    {
        if ($component->relationLoaded('cardProjection')) {
            return $component->getRelation('cardProjection')->getAttribute('connections');
        }
        if (! $component->relationLoaded('connections')) {
            throw new \LogicException('Connections must be batch-loaded.');
        }

        return $component->connections->map(function ($connection) {
            if (! $connection->relationLoaded('targets') || ! $connection->relationLoaded('unresolvedTargets')) {
                throw new \LogicException('Connection targets must be batch-loaded.');
            }
            $targets = $connection->targets->pluck('target_component_id')->all();
            $unresolved = $connection->unresolvedTargets->pluck('source_reference')->all();

            return ['id' => $connection->id, ...$connection->only(self::FIELDS), 'targets' => $targets, 'unresolved_targets' => $unresolved, 'complete' => count($targets) > 0 && count($unresolved) === 0 && $connection->joining_method !== null];
        })->all();
    }

    /** All components have been persisted first, so forward references are valid in the same write. */
    public function apply(Equipment $equipment, Collection $components, array $items, int $actor): void
    {
        $patches = collect($items)->filter(fn ($item) => array_key_exists('connections', $item));
        if ($patches->isEmpty()) {
            return;
        }
        $ids = $patches->flatMap(fn ($item) => array_column($item['connections'], 'id'))->all();
        $existing = ComponentConnection::with(['targets', 'unresolvedTargets'])->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($patches as $item) {
            $component = $components->get($item['id']);
            foreach ($item['connections'] as $input) {
                $old = $existing->get($input['id']);
                if ($old && ($old->equipment_id !== $equipment->id || $old->component_id !== $component->id)) {
                    throw ValidationException::withMessages(['structure' => 'Принадлежность связи неизменна.']);
                }
                $connection = $old ?? new ComponentConnection;
                $connection->fill($input);
                if ($connection->side === 'coil' && ! $component->is_coil) {
                    throw ValidationException::withMessages(['structure' => 'Связь тела змеевика задаётся только у самостоятельного змеевика.']);
                }
                $oldTargets = $old?->targets->pluck('target_component_id')->all() ?? [];
                $targets = $input['targets'] ?? $oldTargets;
                $oldUnresolved = $old?->unresolvedTargets->pluck('source_reference')->all() ?? [];
                $unresolved = $input['unresolved_targets'] ?? $oldUnresolved;
                if (count($unresolved) !== count(array_unique($unresolved))) {
                    throw ValidationException::withMessages(['structure' => 'Исходная неразрешённая ссылка не должна повторяться.']);
                }
                if (count($targets) !== count(array_unique($targets))) {
                    throw ValidationException::withMessages(['structure' => 'Цель не должна повторяться в одной связи.']);
                }
                foreach ($targets as $target) {
                    if ($target === $component->id || ! $components->has($target)) {
                        throw ValidationException::withMessages(['structure' => 'Цель должна принадлежать этой ЕО и отличаться от источника.']);
                    }
                }
                $meaningful = count($targets) > 0 || count($unresolved) > 0 || collect(['side', 'joining_method', 'collar_present', 'thread_kind', 'thread_size', 'notes', 'source_reference'])->contains(fn ($field) => $connection->getAttribute($field) !== null);
                if (! $meaningful && ! $old) {
                    continue;
                }
                if (! $meaningful && $connection->status === 'active') {
                    throw ValidationException::withMessages(['structure' => 'Задайте параметры связи либо явно удалите её из действующих.']);
                }
                if (! $old) {
                    $connection->forceFill(['id' => $input['id'], 'equipment_id' => $equipment->id, 'component_id' => $component->id, 'created_by' => $actor]);
                }
                if (! $old || $connection->isDirty() || $targets !== $oldTargets || $unresolved !== $oldUnresolved) {
                    $connection->updated_by = $actor;
                    $connection->save();
                }
                if ($targets !== $oldTargets) {
                    $db = DB::connection('tenant');
                    $query = $db->table('equipment_component_connection_targets')->where('connection_id', $connection->id);
                    $targets ? $query->whereNotIn('target_component_id', $targets)->delete() : $query->delete();
                    $rows = [];
                    foreach ($targets as $position => $target) {
                        $rows[] = ['connection_id' => $connection->id, 'equipment_id' => $equipment->id, 'target_component_id' => $target, 'position' => $position, 'created_at' => now()];
                    }
                    if ($rows) {
                        $db->table('equipment_component_connection_targets')->upsert($rows, ['connection_id', 'target_component_id'], ['position']);
                    }
                }
                if ($unresolved !== $oldUnresolved) {
                    $db = DB::connection('tenant');
                    $query = $db->table('equipment_component_unresolved_targets')->where('connection_id', $connection->id);
                    $unresolved ? $query->whereNotIn('source_reference', $unresolved)->delete() : $query->delete();
                    $rows = [];
                    foreach ($unresolved as $position => $reference) {
                        $rows[] = ['connection_id' => $connection->id, 'equipment_id' => $equipment->id, 'source_reference' => $reference, 'position' => $position, 'created_at' => now()];
                    }
                    if ($rows) {
                        $db->table('equipment_component_unresolved_targets')->upsert($rows, ['connection_id', 'source_reference'], ['position']);
                    }
                }
            }
        }
    }

    public static function rules(): array
    {
        return ['structure.*.connections' => 'sometimes|array|list|max:100', 'structure.*.connections.*' => 'required|array:id,'.implode(',', self::FIELDS).',targets,unresolved_targets',
            'structure.*.connections.*.unresolved_targets' => 'sometimes|array|list|max:1000', 'structure.*.connections.*.unresolved_targets.*' => 'required|string|max:255',
            'structure.*.connections.*.id' => 'required|uuid|distinct', 'structure.*.connections.*.status' => 'sometimes|in:active,removed', 'structure.*.connections.*.position' => 'sometimes|integer|min:0|max:100000',
            'structure.*.connections.*.side' => 'sometimes|nullable|in:coil', 'structure.*.connections.*.joining_method' => 'sometimes|nullable|string|max:255', 'structure.*.connections.*.collar_present' => 'sometimes|nullable|boolean',
            'structure.*.connections.*.thread_kind' => 'sometimes|nullable|string|max:255', 'structure.*.connections.*.thread_size' => 'sometimes|nullable|string|max:255', 'structure.*.connections.*.notes' => 'sometimes|nullable|string|max:10000',
            'structure.*.connections.*.source_reference' => 'sometimes|nullable|string|max:1000', 'structure.*.connections.*.targets' => 'sometimes|array|list|max:1000', 'structure.*.connections.*.targets.*' => 'required|uuid'];
    }
}
