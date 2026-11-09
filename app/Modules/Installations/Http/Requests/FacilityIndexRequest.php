<?php

namespace App\Modules\Installations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FacilityIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['q' => 'sometimes|nullable|string|max:255', 'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100', 'parent_id' => 'sometimes|nullable|uuid',
            'kind' => 'sometimes|in:enterprise,site,production', 'status' => 'sometimes|in:active,archived'];
    }
}
