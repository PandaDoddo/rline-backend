<?php

namespace App\Modules\Diagnostics\Application;

use App\Modules\Diagnostics\Models\EquipmentInspection;
use App\Modules\Equipment\Models\Equipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EquipmentDiagnostics
{
    public const KINDS = ['ТО-1', 'ТО-2', 'ЭПБ', 'ТО-RBI', 'ГИ', 'ПИ', 'ГЕОД-И', 'НО', 'УЗТ', 'другое'];

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
            if ($primary->verified_at || DB::connection('tenant')->table('equipment_thickness_stages')->where('inspection_id', $primary->id)->whereNotNull('verified_at')->exists()) {
                throw ValidationException::withMessages(['passport.registration_authority' => 'Сначала снимите проверку первичной диагностики и связанного этапа УЗТ.']);
            }
            $primary->update(['kind' => $kind]);
        }
    }

    public function apply(Equipment $equipment, array $items, int $actor, array $visualResets = []): void
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
            $candidate = clone $row;
            $candidate->fill($input);
            if (array_key_exists('follow_up_dates', $input)) {
                $candidate->follow_up_dates = (object) $input['follow_up_dates'];
            }
            if ($row->verified_at && $candidate->isDirty()) {
                throw ValidationException::withMessages(['inspections' => 'Проверенная диагностика защищена. Сначала снимите проверку отдельным сохранением.']);
            }
            if ($candidate->isDirty('performed_on') && DB::connection('tenant')->table('equipment_thickness_stages')->where('inspection_id', $row->id)->whereNotNull('verified_at')->exists()) {
                throw ValidationException::withMessages(['inspections' => 'Дата диагностики связана с проверенным этапом УЗТ. Сначала снимите проверку этапа.']);
            }
            if ($row->exists && $candidate->isDirty('performed_on')) {
                $visual = DB::connection('tenant')->table('equipment_visual_stages')->where('inspection_id', $row->id)->first();
                if ($visual && $visual->verified_at !== null) {
                    throw ValidationException::withMessages(['inspections' => 'Сначала снимите проверку связанного этапа ВИК отдельным сохранением.']);
                }
                if ($visual && ! in_array($visual->id, $visualResets, true)) {
                    if ($candidate->performed_on === null) {
                        throw ValidationException::withMessages(['inspections' => 'Сначала очистите связанный этап ВИК перед удалением даты выполненной диагностики.']);
                    }
                    $collision = DB::connection('tenant')->table('equipment_visual_stages as s')->leftJoin('equipment_inspections as i', 'i.id', '=', 's.inspection_id')
                        ->where('s.equipment_id', $equipment->id)->where('s.id', '<>', $visual->id)->whereNotIn('s.id', $visualResets)
                        ->whereRaw('coalesce(i.performed_on,s.reference_date) = ?::date', [$candidate->performed_on->format('Y-m-d')])->exists();
                    if ($collision) {
                        throw ValidationException::withMessages(['inspections' => 'На новую дату уже есть сохранённый этап ВИК. Его данные нельзя молча объединить.']);
                    }
                }
            }
            foreach ($candidate->unknown_fields as $key) {
                $value = in_array($key, self::KINDS, true) ? ($candidate->follow_up_dates[$key] ?? null) : $candidate->$key;
                if ($value !== null && $value !== '') {
                    throw ValidationException::withMessages(['inspections' => 'Неизвестное значение должно быть пустым: '.$key]);
                }
            }
            $verified = $input['verified'] ?? ($row->verified_at !== null);
            $candidate->forceFill(['verified_at' => $verified ? ($row->verified_at ?? now()) : null, 'verified_by' => $verified ? ($row->verified_by ?? $actor) : null])->save();
        }
    }

    public static function rules(): array
    {
        return ['inspections' => 'sometimes|array|max:500', 'inspections.*' => 'required|array:id,kind,is_primary,planned_on,performed_on,report_number,notes,next_on,organization,inspection_type,follow_up_dates,unknown_fields,verified',
            'inspections.*.id' => 'required|uuid|distinct', 'inspections.*.kind' => ['required', Rule::in(self::KINDS)],
            'inspections.*.is_primary' => 'required|boolean', 'inspections.*.planned_on' => 'nullable|date_format:Y-m-d',
            'inspections.*.performed_on' => 'nullable|date_format:Y-m-d', 'inspections.*.report_number' => 'nullable|string|max:255', 'inspections.*.notes' => 'nullable|string|max:10000',
            'inspections.*.next_on' => 'nullable|date_format:Y-m-d', 'inspections.*.organization' => 'nullable|string|max:1000', 'inspections.*.inspection_type' => 'nullable|string|max:100',
            'inspections.*.follow_up_dates' => 'sometimes|array:'.implode(',', self::KINDS), 'inspections.*.follow_up_dates.*' => 'nullable|date_format:Y-m-d',
            'inspections.*.unknown_fields' => 'sometimes|array|list|max:20', 'inspections.*.unknown_fields.*' => ['required', Rule::in(['planned_on', 'performed_on', 'next_on', 'organization', ...self::KINDS])],
            'inspections.*.verified' => 'sometimes|boolean'];
    }
}
