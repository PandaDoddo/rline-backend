<?php

namespace App\Domain;

use App\Models\Equipment;
use App\Models\EquipmentInspection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EquipmentDiagnostics
{
    public function ensurePrimary(Equipment $equipment): void
    {
        $profile = config('equipment.profiles')[$equipment->profile_code];
        if ($profile['g'] !== 'ves') {
            return;
        }
        $kind = ($equipment->passport['registration_authority'] ?? $profile['rp']) === 'РТН' ? 'ТО-2' : 'ТО-1';
        $primary = EquipmentInspection::where('equipment_id', $equipment->id)->where('is_primary', true)->first();
        if (! $primary) {
            (new EquipmentInspection(['kind' => $kind]))->forceFill(['equipment_id' => $equipment->id, 'is_primary' => true])->save();
        } elseif ($primary->performed_on === null && $primary->kind !== $kind) {
            $primary->update(['kind' => $kind]);
        }
    }

    public function apply(Equipment $equipment, array $items): void
    {
        $rows = EquipmentInspection::where('equipment_id', $equipment->id)->lockForUpdate()->get()->keyBy('id');
        foreach ($items as $input) {
            $row = $rows->get($input['id']);
            if (! $row && EquipmentInspection::whereKey($input['id'])->exists()) {
                throw ValidationException::withMessages(['inspections' => 'Диагностика принадлежит другой ЕО.']);
            }
            if ($row && ($row->is_primary !== $input['is_primary'] || $row->kind !== $input['kind'])) {
                throw ValidationException::withMessages(['inspections' => 'Вид и признак первичной диагностики изменять нельзя.']);
            }
            if (! $row && $input['is_primary']) {
                throw ValidationException::withMessages(['inspections' => 'Первичную диагностику создаёт система.']);
            }
            $row ??= (new EquipmentInspection)->forceFill(['id' => $input['id'], 'equipment_id' => $equipment->id]);
            $row->fill($input)->save();
        }
    }

    public static function rules(): array
    {
        return ['inspections' => 'sometimes|array|max:500', 'inspections.*' => 'required|array:id,kind,is_primary,planned_on,performed_on,report_number,notes',
            'inspections.*.id' => 'required|uuid|distinct', 'inspections.*.kind' => ['required', Rule::in(['ТО-1', 'ТО-2', 'ЭПБ', 'ГИ', 'ПИ', 'ГЕОД-И', 'НО', 'УЗТ'])],
            'inspections.*.is_primary' => 'required|boolean', 'inspections.*.planned_on' => 'nullable|date_format:Y-m-d',
            'inspections.*.performed_on' => 'nullable|date_format:Y-m-d', 'inspections.*.report_number' => 'nullable|string|max:255', 'inspections.*.notes' => 'nullable|string|max:10000'];
    }
}
