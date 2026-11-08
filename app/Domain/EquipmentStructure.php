<?php

namespace App\Domain;

use App\Models\Equipment;
use App\Models\EquipmentComponent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EquipmentStructure
{
    public function apply(Equipment $equipment, array $items): void
    {
        $rows = EquipmentComponent::where('equipment_id', $equipment->id)->lockForUpdate()->get()->keyBy('id');
        // Roots first: an element may refer to a root created in this same atomic save.
        usort($items, fn ($a, $b) => (int) ($a['parent_id'] !== null) <=> (int) ($b['parent_id'] !== null));
        foreach ($items as $input) {
            $existing = $rows->get($input['id']);
            if (! $existing && EquipmentComponent::whereKey($input['id'])->exists()) {
                throw ValidationException::withMessages(['structure' => 'Компонент принадлежит другой ЕО.']);
            }
            $parent = $input['parent_id'] ? $rows->get($input['parent_id']) : null;
            if ($input['parent_id'] && (! $parent || $parent->parent_id !== null || $input['kind'] !== 'element')) {
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
            $row = $existing ?? new EquipmentComponent;
            if (! $existing) {
                $number = $parent ? $parent->next_element_number++ : $equipment->next_component_number++;
                $code = $parent ? $parent->code.'.S'.$number : 'K-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                $row->forceFill(['id' => $input['id'], 'equipment_id' => $equipment->id, 'parent_id' => $input['parent_id'], 'kind' => $input['kind'], 'is_coil' => $input['is_coil'], 'code' => $code]);
                $parent?->save();
            }
            $row->fill($input)->save();
            $rows->put($row->id, $row);
        }
        // Omitted records are retained. Demounting is explicit and never renumbers identifiers.
    }

    public static function rules(): array
    {
        return ['structure' => 'sometimes|array|max:500', 'structure.*' => 'required|array:id,parent_id,kind,name,is_coil,status,position,material,diameter_mm,length_mm,nominal_thickness_mm,rejection_passport_mm,rejection_epb_mm,notes,flow_space',
            'structure.*.flow_space' => 'sometimes|nullable|in:tube,shell',
            'structure.*.id' => 'required|uuid|distinct', 'structure.*.parent_id' => 'present|nullable|uuid',
            'structure.*.kind' => ['required', Rule::in(['shell', 'head', 'nozzle', 'coil', 'tube_bundle', 'pipe', 'element', 'other'])],
            'structure.*.name' => 'required|string|min:1|max:255', 'structure.*.is_coil' => 'required|boolean',
            'structure.*.status' => ['required', Rule::in(['active', 'removed'])], 'structure.*.position' => 'required|integer|min:0|max:100000',
            'structure.*.material' => 'nullable|string|max:255', 'structure.*.diameter_mm' => 'nullable|numeric|gt:0|max:1000000',
            'structure.*.length_mm' => 'nullable|numeric|gt:0|max:100000000', 'structure.*.nominal_thickness_mm' => 'nullable|numeric|gt:0|max:100000',
            'structure.*.rejection_passport_mm' => 'nullable|numeric|gt:0|max:100000', 'structure.*.rejection_epb_mm' => 'nullable|numeric|gt:0|max:100000',
            'structure.*.notes' => 'nullable|string|max:10000'];
    }
}
