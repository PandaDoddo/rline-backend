<?php

namespace App\Modules\Equipment\Http\Requests;

use App\Modules\Equipment\Domain\EquipmentGroupSelection;
use Illuminate\Foundation\Http\FormRequest;

class EquipmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['q' => 'sometimes|nullable|string|max:255', 'installation_id' => 'sometimes|nullable|uuid', 'facility_node_id' => 'sometimes|nullable|uuid',
            'group' => ['sometimes', 'nullable', 'string', 'max:200', EquipmentGroupSelection::rule()], 'status' => 'sometimes|in:active,archived',
            'sort' => 'sometimes|in:name,tag,registration_number,commissioned_on,updated_at', 'direction' => 'sometimes|in:asc,desc',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
