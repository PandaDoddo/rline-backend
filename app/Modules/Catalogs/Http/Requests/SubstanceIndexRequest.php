<?php

namespace App\Modules\Catalogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubstanceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['phases' => 'sometimes|array|max:3', 'phases.*' => 'required|distinct|in:газ,жидкость,твёрдое', 'criteria' => 'sometimes|array|max:4', 'criteria.*' => 'required|distinct|in:flammable,toxic,nfnt,inert', 'q' => 'sometimes|nullable|string|max:255', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'status' => 'sometimes|in:active,archived', 'origin' => 'sometimes|in:base,custom', 'criterion' => 'sometimes|in:flammable,toxic,nfnt,inert',
            'criterion_value' => 'sometimes|in:yes,no,unknown', 'group' => ['sometimes', 'nullable', Rule::in(config('substances.fields.grpRF.options'))]];
    }
}
