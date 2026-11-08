<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EquipmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['q' => 'sometimes|nullable|string|max:255', 'installation_id' => 'sometimes|nullable|uuid',
            'group' => 'sometimes|nullable|string|max:30', 'status' => 'sometimes|in:active,archived',
            'sort' => 'sometimes|in:name,tag,registration_number,commissioned_on,updated_at', 'direction' => 'sometimes|in:asc,desc',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
