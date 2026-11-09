<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Domain\ComponentCode;
use App\Modules\Equipment\Domain\ComponentConstructionSchema;
use App\Modules\Equipment\Domain\ComponentDetailSchema;
use App\Modules\Equipment\Domain\ComponentTree;
use App\Modules\Equipment\Models\ComponentType;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Equipment\Models\EquipmentComponent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EquipmentStructure
{
    public function __construct(private readonly ComponentSpecifications $specifications, private readonly ComponentPorts $ports, private readonly ComponentConnections $connections, private readonly ComponentHistory $history, private readonly ComponentAssemblies $assemblies) {}

    public function apply(Equipment $equipment, array $items, int $actor): void
    {
        $rows = EquipmentComponent::with(ComponentSpecifications::componentRelations())->where('equipment_id', $equipment->id)->lockForUpdate()->get()->keyBy('id');
        $types = ComponentType::query()->get()->keyBy('code');
        if (EquipmentComponent::whereIn('id', array_column($items, 'id'))->where('equipment_id', '<>', $equipment->id)->exists()) {
            throw ValidationException::withMessages(['structure' => 'Компонент принадлежит другой ЕО.']);
        }
        $candidate = $rows->toArray();
        foreach ($items as $input) {
            $candidate[$input['id']] = array_replace($candidate[$input['id']] ?? [], $input);
        }
        $tree = new ComponentTree(array_values($candidate));
        $depths = [];
        try {
            foreach ($items as $input) {
                $depths[$input['id']] = count($tree->lineage($input['id']));
                $role = $candidate[$input['id']]['coil_role'] ?? null;
                if (($candidate[$input['id']]['type_code'] ?? null) === 'coil_body' && ! $tree->root($input['id'])['is_coil']) {
                    throw ValidationException::withMessages(['structure' => 'Тело змеевика должно принадлежать самостоятельному змеевику.']);
                }
                if ($role !== null && ($input['parent_id'] === null || ! $tree->root($input['id'])['is_coil'])) {
                    throw ValidationException::withMessages(['structure' => 'Роль входа/тела/выхода разрешена только элементам самостоятельного змеевика.']);
                }
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['structure' => $exception->getMessage()]);
        }
        // Parents before descendants, even when an entire assembly arrives unordered.
        usort($items, fn ($a, $b) => $depths[$a['id']] <=> $depths[$b['id']]);
        foreach ($items as $input) {
            $existing = $rows->get($input['id']);
            $typeCode = array_key_exists('type_code', $input) ? $input['type_code'] : $existing?->type_code;
            if ($typeCode !== null) {
                $type = $types->get($typeCode);
                if (! $type || ($input['parent_id'] === null ? $type->root_kind === null || $type->root_kind !== $input['kind'] : ! $type->element_allowed)) {
                    throw ValidationException::withMessages(['structure' => 'Точный тип компонента несовместим с его видом или положением в сборке.']);
                }
            }
            $parent = $input['parent_id'] ? $rows->get($input['parent_id']) : null;
            if ($input['parent_id'] && (! $parent || $input['kind'] !== 'element')) {
                throw ValidationException::withMessages(['structure' => 'Элемент должен ссылаться на компонент этой же ЕО.']);
            }
            if (! $input['parent_id'] && $input['kind'] === 'element') {
                throw ValidationException::withMessages(['structure' => 'Для элемента требуется родительский компонент.']);
            }
            if ($input['is_coil'] && ($input['parent_id'] !== null || $input['kind'] !== 'coil')) {
                throw ValidationException::withMessages(['structure' => 'Змеевик является самостоятельным компонентом.']);
            }
            if ($input['kind'] === 'coil' && ! $input['is_coil']) {
                throw ValidationException::withMessages(['structure' => 'Для змеевика необходима самостоятельная область расчёта.']);
            }
            if ($existing && ($existing->parent_id !== $input['parent_id'] || $existing->kind !== $input['kind'] || $existing->is_coil !== $input['is_coil'])) {
                throw ValidationException::withMessages(['structure' => 'Тип, родитель и область компонента неизменны; создайте новый компонент.']);
            }
            if ($existing && isset($input['code']) && $input['code'] !== $existing->code) {
                throw ValidationException::withMessages(['structure' => 'Код существующего компонента неизменяем.']);
            }
            $row = $existing ?? new EquipmentComponent;
            if (! $existing) {
                try {
                    $allocated = (new ComponentCode)->allocate($input['code'] ?? null, $parent?->code, $parent ? $parent->next_element_number : $equipment->next_component_number);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['structure' => $exception->getMessage()]);
                }
                $code = $allocated['code'];
                if ($parent) {
                    $parent->next_element_number = $allocated['next'];
                } else {
                    $equipment->next_component_number = $allocated['next'];
                }
                $row->forceFill(['id' => $input['id'], 'equipment_id' => $equipment->id, 'parent_id' => $input['parent_id'], 'kind' => $input['kind'], 'is_coil' => $input['is_coil'], 'code' => $code]);
                $parent?->save();
            }
            $row->fill($input)->save();
            if ($row->wasRecentlyCreated) {
                foreach (array_diff(ComponentSpecifications::componentRelations(), ['ports']) as $relation) {
                    $row->setRelation($relation, null);
                }
                $row->setRelation('ports', collect());
            }
            $this->specifications->apply($row, $input);
            if (array_key_exists('ports', $input)) {
                $this->ports->apply($row, $input['ports']);
            }
            if (array_key_exists('construction', $input)) {
                $construction = $row->construction ?? $row->construction()->make();
                $construction->fill($input['construction'] ?? array_fill_keys(array_keys(ComponentConstructionSchema::LABELS), null));
                if ($construction->coil_body_kind !== null && ! $row->is_coil) {
                    throw ValidationException::withMessages(['structure' => 'Структура тела задаётся только у самостоятельного змеевика.']);
                }
                if (collect(array_keys(ComponentConstructionSchema::LABELS))->contains(fn ($field) => $construction->getAttribute($field) !== null)) {
                    $construction->save();
                } elseif ($construction->exists) {
                    $construction->delete();
                }
                $row->unsetRelation('construction');
            }
            $rows->put($row->id, $row);
        }
        $this->connections->apply($equipment, $rows, $items, $actor);
        $this->history->apply($equipment, $rows, $items, $actor);
        $this->assemblies->apply($equipment, $rows, $items, $actor);
        // Omitted records are retained. Demounting is explicit and never renumbers identifiers.
    }

    public static function rules(): array
    {
        return ['structure' => 'sometimes|array|max:500', 'structure.*' => 'required|array:id,code,parent_id,kind,type_code,coil_role,name,is_coil,status,position,material,diameter_mm,length_mm,nominal_thickness_mm,rejection_passport_mm,rejection_epb_mm,notes,flow_space,construction,head_geometry,fitting_geometry,end_connection,boss_geometry,valve_details,tube_geometry,coil_geometry,lens_geometry,expansion_joint,shell_geometry,ports,connections,history,assemblies',
            'structure.*.coil_role' => 'sometimes|nullable|in:inlet,inlet_boss,body,outlet_boss,outlet',
            'structure.*.type_code' => 'sometimes|nullable|string|max:60',
            'structure.*.code' => 'sometimes|string|max:40',
            'structure.*.flow_space' => 'sometimes|nullable|in:tube,shell',
            'structure.*.id' => 'required|uuid|distinct', 'structure.*.parent_id' => 'present|nullable|uuid',
            'structure.*.kind' => ['required', Rule::in(['shell', 'head', 'nozzle', 'coil', 'tube_bundle', 'pipe', 'element', 'other'])],
            'structure.*.name' => 'required|string|min:1|max:255', 'structure.*.is_coil' => 'required|boolean',
            'structure.*.status' => ['required', Rule::in(['active', 'removed'])], 'structure.*.position' => 'required|integer|min:0|max:100000',
            'structure.*.material' => 'nullable|string|max:255', 'structure.*.diameter_mm' => 'nullable|numeric|gt:0|max:1000000',
            'structure.*.length_mm' => 'nullable|numeric|gt:0|max:100000000', 'structure.*.nominal_thickness_mm' => 'nullable|numeric|gt:0|max:100000',
            'structure.*.rejection_passport_mm' => 'nullable|numeric|gt:0|max:100000', 'structure.*.rejection_epb_mm' => 'nullable|numeric|gt:0|max:100000',
            'structure.*.notes' => 'nullable|string|max:10000'] + ComponentConstructionSchema::rules() + ComponentDetailSchema::rules() + ComponentPorts::rules() + ComponentConnections::rules() + ComponentHistory::rules() + ComponentAssemblies::rules();
    }
}
