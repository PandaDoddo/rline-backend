<?php

namespace App\Http\Requests;

use App\Domain\InstallationCostFactors;
use Illuminate\Validation\Rule;

class UpdateInstallationCostRequest extends UpdateMaterialCostRequest
{
    protected function prepareForValidation(): void
    {
        if (! is_array($values = $this->input('values'))) {
            return;
        }
        foreach ($values as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            $normalized = str_replace([' ', ','], ['', '.'], trim($value));
            $values[$key] = $normalized === '' ? null : (config('installation-costs.fields.'.$key.'.type') === 'number' && is_numeric($normalized) ? (float) $normalized : $value);
        }
        $this->merge(['values' => $values]);
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
