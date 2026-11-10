<?php

namespace App\Modules\Catalogs\Application;

use App\Modules\Catalogs\Domain\SubstanceValues;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** One contract for interactive editing and transactional dataset maintenance. */
final class SubstanceInputValidation
{
    public function validated(array $input, bool $updating = false): array
    {
        return Validator::make($this->normalize($input), $this->rules($updating))->validate();
    }

    public function normalize(array $input): array
    {
        $input = $this->trim($input);
        if (is_array($input['values'] ?? null)) {
            foreach ($input['values'] as $key => $value) {
                $input['values'][$key] = SubstanceValues::normalize($key, $value);
            }
        }

        return $input;
    }

    private function trim(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->trim(...), $value);
        }

        return is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
    }

    public function rules(bool $updating = false): array
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
            $required = $key === 'name' && ! $updating ? 'required' : 'sometimes';
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

        if (! $updating) {
            return $rules;
        }
        $editable = array_keys(array_filter($fields, fn ($field) => ! ($field['readonly'] ?? false)));
        $rules = array_replace($rules, ['values' => 'sometimes|array:'.implode(',', array_keys($fields)), 'version' => 'required|integer|min:1',
            'status' => 'sometimes|in:active,archived', 'reset_fields' => 'sometimes|array|max:100', 'reset_fields.*' => ['required', 'string', 'distinct', Rule::in($editable)],
            'verification' => 'sometimes|array:'.implode(',', array_keys($fields))]);
        foreach (array_keys($fields) as $key) {
            $rules['verification.'.$key] = 'sometimes|boolean';
        }

        return $rules;
    }
}
