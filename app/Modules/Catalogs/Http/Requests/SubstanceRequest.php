<?php

namespace App\Modules\Catalogs\Http\Requests;

use App\Modules\Catalogs\Domain\SubstanceValues;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubstanceRequest extends FormRequest
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
            $values[$key] = SubstanceValues::normalize($key, $value);
        }
        $this->merge(['values' => $values]);
    }

    public function rules(): array
    {
        $fields = config('substances.fields');
        $keys = array_keys($fields);
        $rules = ['values' => ['required', 'array:'.implode(',', $keys)], 'version' => 'prohibited', 'origin' => 'prohibited', 'base_values' => 'prohibited', 'base_version' => 'prohibited', 'checks' => 'prohibited', 'status' => 'prohibited',
            'sources' => 'sometimes|array:'.implode(',', $keys), 'sources.*' => 'required|array:citation,conditions', 'sources.*.citation' => 'nullable|string|max:4000', 'sources.*.conditions' => 'nullable|string|max:4000'];
        foreach ($fields as $key => $field) {
            $property = 'values.'.$key;
            if ($field['readonly'] ?? false) {
                $rules[$property] = 'missing';

                continue;
            }
            $required = $key === 'name' && $this->isMethod('POST') ? 'required' : 'sometimes';
            if ($field['type'] === 'boolean') {
                $rules[$property] = [$required, 'nullable', 'boolean'];
            } elseif ($field['type'] === 'choice') {
                $rules[$property] = [$required, 'nullable', Rule::in($field['options'])];
            } elseif (in_array($field['type'], ['number', 'integer'], true)) {
                $rules[$property] = [$required, 'nullable', function ($attribute, $value, $fail) use ($field) {
                    if (in_array($value, [...config('substances.missing_values'), ...($field['qualifiers'] ?? [])], true)) {
                        return;
                    }
                    if (! is_int($value) && ! is_float($value) || ! is_finite((float) $value) || $field['type'] === 'integer' && floor($value) !== (float) $value || $value < $field['min'] || $value > $field['max']) {
                        $fail($field['label'].': укажите допустимое число или явно обозначьте отсутствие данных.');
                    }
                }];
            } else {
                $rules[$property] = [$required, $key === 'name' ? 'filled' : 'nullable', 'string', 'max:'.($field['max'] ?? 10000)];
            }
        }

        return $rules;
    }
}
