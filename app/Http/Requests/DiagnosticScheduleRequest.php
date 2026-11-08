<?php

namespace App\Http\Requests;

class DiagnosticScheduleRequest extends EquipmentIndexRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), ['sort' => 'sometimes|in:name,tag,registration_number,commissioned_on,updated_at,next_on',
            'kind' => 'sometimes|in:ТО-1,ТО-2,ЭПБ,ГИ,ПИ,ГИ/ПИ,ГЕОД-И,НО,УЗТ', 'plan_state' => 'sometimes|in:all,overdue,upcoming,missing',
            'within_days' => 'sometimes|integer|min:0|max:3650']);
    }
}
