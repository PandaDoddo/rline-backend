<?php

namespace App\Modules\Catalogs\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateSubstanceRequest extends SubstanceRequest
{
    public function rules(): array
    {
        $keys = array_keys(array_filter(config('substances.fields'), fn ($field) => ! ($field['readonly'] ?? false)));
        $rules = array_replace(parent::rules(), ['values' => 'sometimes|array:'.implode(',', array_keys(config('substances.fields'))), 'version' => 'required|integer|min:1',
            'status' => 'sometimes|in:active,archived', 'reset_fields' => 'sometimes|array|max:100', 'reset_fields.*' => ['required', 'string', 'distinct', Rule::in($keys)],
            'verification' => 'sometimes|array:'.implode(',', array_keys(config('substances.fields')))]);
        foreach (array_keys(config('substances.fields')) as $key) {
            $rules['verification.'.$key] = 'sometimes|boolean';
        }

        return $rules;
    }
}
