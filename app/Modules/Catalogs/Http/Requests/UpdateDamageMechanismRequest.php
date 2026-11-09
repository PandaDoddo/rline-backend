<?php

namespace App\Modules\Catalogs\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDamageMechanismRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(app(TenantContext::class)->membership($this->user()->id)?->role, ['owner', 'admin'], true);
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($values = $this->input('values'))) {
            return;
        }
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = trim($value) === '' || trim($value) === '—' ? null : trim($value);
            }
        }
        $this->merge(['values' => $values]);
    }

    public function rules(): array
    {
        $fields = config('damage-mechanisms.fields');
        $keys = array_keys($fields);
        $editable = array_keys(array_filter($fields, fn ($field) => ! ($field['readonly'] ?? false)));
        $rules = ['version' => 'required|integer|min:1', 'values' => 'sometimes|array:'.implode(',', $keys), 'origin' => 'prohibited', 'base_values' => 'prohibited', 'base_version' => 'prohibited', 'checks' => 'prohibited', 'status' => 'sometimes|in:active,archived', 'reset_fields' => 'sometimes|array|max:100', 'reset_fields.*' => ['required', 'string', 'distinct', Rule::in($editable)], 'verification' => 'sometimes|array:'.implode(',', $editable),
            'sources' => 'sometimes|array:'.implode(',', $editable), 'sources.*' => 'required|array:citation,conditions', 'sources.*.citation' => 'nullable|string|max:4000', 'sources.*.conditions' => 'nullable|string|max:4000'];
        foreach ($fields as $key => $field) {
            if ($field['readonly'] ?? false) {
                $rules['values.'.$key] = 'missing';

                continue;
            }
            $rules['verification.'.$key] = 'sometimes|boolean';
            if ($field['type'] === 'groups') {
                $rules['values.'.$key] = 'sometimes|array|max:11';
                $rules['values.'.$key.'.*'] = ['required', 'string', 'distinct', Rule::in(array_keys(config('damage-mechanisms.groups')))];
            } elseif ($field['type'] === 'choice') {
                $rules['values.'.$key] = ['sometimes', 'nullable', Rule::in($field['options'])];
            } else {
                $rules['values.'.$key] = ['sometimes', $key === 'name' ? 'filled' : 'nullable', 'string', 'max:'.$field['max']];
            }
        }

        return $rules;
    }
}
