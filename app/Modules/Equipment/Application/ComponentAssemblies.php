<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Domain\ComponentTree;
use App\Modules\Equipment\Models\ComponentAssembly;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Equipment\Models\EquipmentComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Logical assemblies reference existing physical parts without changing their CML coordinates. */
final class ComponentAssemblies
{
    public const FIELDS = ['status', 'position', 'after_component_id', 'nominal_pressure_mpa', 'joint_kind', 'jacket_present', 'manufacturing_standard', 'notes', 'source_reference'];

    public function read(EquipmentComponent $component): array
    {
        if ($component->relationLoaded('cardProjection')) {
            return $component->getRelation('cardProjection')->getAttribute('assemblies');
        }
        if (! $component->relationLoaded('assemblies')) {
            throw new \LogicException('Component assemblies must be batch-loaded.');
        }

        return $component->assemblies->map(function ($assembly) {
            if (! $assembly->relationLoaded('members')) {
                throw new \LogicException('Assembly members must be batch-loaded.');
            }

            return [...$assembly->only(['id', 'code', 'kind', ...self::FIELDS]), 'members' => $assembly->members->pluck('member_component_id')->all()];
        })->all();
    }

    public function apply(Equipment $equipment, Collection $components, array $items, int $actor): void
    {
        $patches = collect($items)->filter(fn ($row) => array_key_exists('assemblies', $row));
        if ($patches->isEmpty()) {
            return;
        }
        $existing = ComponentAssembly::with('members')->whereIn('id', $patches->flatMap(fn ($row) => array_column($row['assemblies'], 'id')))->get()->keyBy('id');
        $tree = new ComponentTree($components->values()->map(fn ($component) => $component->only(['id', 'parent_id', 'is_coil']))->all());
        foreach ($patches as $item) {
            foreach ($item['assemblies'] as $input) {
                $old = $existing->get($input['id']);
                if ($old && ($old->equipment_id !== $equipment->id || $old->component_id !== $item['id'] || $old->code !== $input['code'] || $old->kind !== $input['kind'])) {
                    throw ValidationException::withMessages(['structure' => 'Принадлежность, код и вид сборки неизменны.']);
                }
                $assembly = $old ?? new ComponentAssembly;
                $assembly->fill($input);
                $members = $input['members'] ?? $old?->members->pluck('member_component_id')->all() ?? [];
                if (count($members) !== count(array_unique($members))) {
                    throw ValidationException::withMessages(['structure' => 'Элемент не должен повторяться в одной сборке.']);
                }
                if (count($members) === 0 && $assembly->status === 'active') {
                    throw ValidationException::withMessages(['structure' => 'У действующей сборки должен быть хотя бы один физический элемент.']);
                }
                foreach ([...$members, ...($assembly->after_component_id ? [$assembly->after_component_id] : [])] as $member) {
                    if (! $components->has($member) || $member === $item['id'] || ! in_array($item['id'], array_column($tree->lineage($member), 'id'), true)) {
                        throw ValidationException::withMessages(['structure' => 'Элементы и предшественник сборки должны находиться в поддереве её компонента.']);
                    }
                }
                if (in_array($assembly->after_component_id, $members, true)) {
                    throw ValidationException::withMessages(['structure' => 'Предшествующий элемент не входит в состав компенсатора.']);
                }
                if (ComponentAssembly::where('component_id', $item['id'])->where('code', $input['code'])->whereKeyNot($input['id'])->exists()) {
                    throw ValidationException::withMessages(['structure' => 'Код сборки уже занят у этого компонента.']);
                }
                if (! $old) {
                    $assembly->forceFill(['id' => $input['id'], 'equipment_id' => $equipment->id, 'component_id' => $item['id'], 'code' => $input['code'], 'kind' => $input['kind'], 'created_by' => $actor]);
                }
                $oldMembers = $old?->members->pluck('member_component_id')->all() ?? [];
                if (! $old || $assembly->isDirty() || $members !== $oldMembers) {
                    $assembly->updated_by = $actor;
                    $assembly->save();
                }
                if ($members !== $oldMembers) {
                    $db = DB::connection('tenant');
                    $query = $db->table('equipment_component_assembly_members')->where('assembly_id', $assembly->id);
                    $members ? $query->whereNotIn('member_component_id', $members)->delete() : $query->delete();
                    $rows = [];
                    foreach ($members as $position => $member) {
                        $rows[] = ['assembly_id' => $assembly->id, 'equipment_id' => $equipment->id, 'member_component_id' => $member, 'position' => $position, 'created_at' => now()];
                    }
                    if ($rows) {
                        $db->table('equipment_component_assembly_members')->upsert($rows, ['assembly_id', 'member_component_id'], ['position']);
                    }
                }
            }
        }
    }

    public static function rules(): array
    {
        return ['structure.*.assemblies' => 'sometimes|array|list|max:100',
            'structure.*.assemblies.*' => 'required|array:id,code,kind,'.implode(',', self::FIELDS).',members',
            'structure.*.assemblies.*.id' => 'required|uuid|distinct', 'structure.*.assemblies.*.code' => 'required|string|min:1|max:40',
            'structure.*.assemblies.*.kind' => 'required|in:lens_expansion_joint', 'structure.*.assemblies.*.status' => 'sometimes|in:active,removed',
            'structure.*.assemblies.*.position' => 'sometimes|integer|min:0|max:100000', 'structure.*.assemblies.*.after_component_id' => 'sometimes|nullable|uuid',
            'structure.*.assemblies.*.nominal_pressure_mpa' => 'sometimes|nullable|numeric|min:0|max:100000', 'structure.*.assemblies.*.joint_kind' => 'sometimes|nullable|in:осевой,угловой',
            'structure.*.assemblies.*.jacket_present' => 'sometimes|nullable|boolean', 'structure.*.assemblies.*.manufacturing_standard' => 'sometimes|nullable|string|max:255',
            'structure.*.assemblies.*.notes' => 'sometimes|nullable|string|max:10000', 'structure.*.assemblies.*.source_reference' => 'sometimes|nullable|string|max:1000',
            'structure.*.assemblies.*.members' => 'sometimes|array|list|max:500', 'structure.*.assemblies.*.members.*' => 'required|uuid'];
    }
}
