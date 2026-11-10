<?php

namespace App\Modules\Installations\Application;

use App\Modules\Installations\Domain\InstallationCostFactors;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Public API and dataset maintenance share one cost contract. */
final class InstallationCostInputValidation
{
    public function validated(array $input): array
    {
        return Validator::make($this->normalize($input), $this->rules())->validate();
    }

    public function normalize(array $input): array
    {
        $input = $this->trim($input);
        if (is_array($input['values'] ?? null)) {
            foreach ($input['values'] as $key => $value) {
                if (is_string($value) && config('installation-costs.fields.'.$key.'.type') === 'number') {
                    $number = str_replace([' ', "\u{00A0}", "\u{202F}", ',', '−'], ['', '', '', '.', '-'], $value);
                    $input['values'][$key] = is_numeric($number) ? $number + 0 : $value;
                }
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

    public function rules(): array
    {
        $fields = config('installation-costs.fields');
        $keys = implode(',', array_keys($fields));
        $tables = app(InstallationCostFactors::class)->tables();
        $rules = ['version' => 'required|integer|min:1', 'values' => 'sometimes|array:'.$keys, 'sources' => 'sometimes|array:'.$keys,
            'sources.*' => 'required|array:citation,conditions', 'sources.*.citation' => 'nullable|string|max:4000', 'sources.*.conditions' => 'nullable|string|max:4000', 'verification' => 'sometimes|array:'.$keys];
        foreach ($fields as $key => $field) {
            $rules['verification.'.$key] = 'sometimes|boolean';
            if ($field['type'] === 'number') {
                $rules['values.'.$key] = 'sometimes|nullable|numeric|min:0|max:'.$field['max'];
            } else {
                $options = $field['options'] === 'mit' ? ['none', ...array_map('strval', range(1, count($tables['mitigation'])))] : array_column(array_filter($tables['systems'], fn ($row) => $row['sys'] === $field['options']), 'n');
                $rules['values.'.$key] = ['sometimes', 'nullable', 'string', Rule::in($options)];
                $rules['sources.'.$key.'.citation'] = ['sometimes', 'nullable', Rule::in([$field['source']])];
            }
        }

        return $rules;
    }
}
