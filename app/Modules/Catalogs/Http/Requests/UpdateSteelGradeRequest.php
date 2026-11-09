<?php

namespace App\Modules\Catalogs\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateSteelGradeRequest extends SteelGradeRequest
{
    public function rules(): array
    {
        $keys = array_keys(config('steel-grades.fields'));
        $writable = array_keys(array_filter(config('steel-grades.fields'), fn ($field) => ! ($field['readonly'] ?? false)));
        $rules = array_replace(parent::rules(), ['values' => 'sometimes|array:'.implode(',', $keys), 'version' => 'required|integer|min:1', 'status' => 'sometimes|in:active,archived', 'reset_fields' => 'sometimes|array|max:100', 'reset_fields.*' => ['required', 'string', 'distinct', Rule::in($writable)], 'verification' => 'sometimes|array:'.implode(',', $keys)]);
        foreach ($keys as $key) {
            $rules['verification.'.$key] = 'sometimes|boolean';
        }

        return $rules;
    }
}
