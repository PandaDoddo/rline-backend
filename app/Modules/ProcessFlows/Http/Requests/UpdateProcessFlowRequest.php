<?php

namespace App\Modules\ProcessFlows\Http\Requests;

use App\Http\Requests\TenantWriteRequest;
use App\Modules\ProcessFlows\Domain\FlowScreening;
use Illuminate\Validation\Rule;

class UpdateProcessFlowRequest extends TenantWriteRequest
{
    protected function prepareForValidation(): void
    {
        if (is_array($attributes = $this->input('attributes'))) {
            foreach ($attributes as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }
                $value = trim($value);
                if ($value === '') {
                    $attributes[$key] = null;
                } elseif (config('process-flows.fields.'.$key.'.type') === 'number' && is_numeric($normalized = str_replace([' ', ','], ['', '.'], $value))) {
                    $attributes[$key] = (float) $normalized;
                }
            }
            $this->merge(['attributes' => $attributes]);
        }
        if (is_array($lines = $this->input('composition'))) {
            foreach ($lines as &$line) {
                if (! is_array($line)) {
                    continue;
                }
                if (is_string($line['amount'] ?? null)) {
                    $value = str_replace([' ', ','], ['', '.'], trim($line['amount']));
                    $line['amount'] = $value === '' ? null : (is_numeric($value) ? (float) $value : $line['amount']);
                }
            }
            unset($line);
            $this->merge(['composition' => $lines]);
        }
        if (is_array($screening = $this->input('screening')) && is_array($screening['values'] ?? null)) {
            foreach ($screening['values'] as $key => $value) {
                if (is_string($value)) {
                    $normalized = str_replace([' ', ','], ['', '.'], trim($value));
                    $screening['values'][$key] = $normalized === '' ? null : (is_numeric($normalized) ? (float) $normalized : $value);
                }
            }
            $this->merge(['screening' => $screening]);
        }
    }

    public function rules(): array
    {
        $keys = array_keys(config('process-flows.fields'));
        $rules = ['version' => 'required|integer|min:1', 'basis' => 'sometimes|in:mass,mole', 'attributes' => 'sometimes|array:'.implode(',', $keys),
            'sources' => 'sometimes|array:'.implode(',', $keys), 'sources.*' => 'required|array:citation,conditions', 'sources.*.citation' => 'nullable|string|max:4000', 'sources.*.conditions' => 'nullable|string|max:4000',
            'verification' => 'sometimes|array:'.implode(',', $keys), 'composition' => 'sometimes|array|list|max:300',
            'composition.*' => 'required|array:id,substance_id,role,name,amount,unit,plus,notes,position', 'composition.*.id' => 'required|uuid|distinct',
            'composition.*.substance_id' => 'sometimes|nullable|uuid', 'composition.*.role' => 'required|in:main,impurity,reference', 'composition.*.name' => 'sometimes|nullable|string|max:255',
            'composition.*.amount' => 'sometimes|nullable|numeric|min:0|max:1000000000000', 'composition.*.unit' => ['sometimes', 'nullable', Rule::in(config('process-flows.impurity_units'))],
            'composition.*.plus' => 'sometimes|boolean', 'composition.*.notes' => 'sometimes|nullable|string|max:4000', 'composition.*.position' => 'sometimes|integer|min:0|max:10000',
            'delete_composition_ids' => 'sometimes|array|list|max:300', 'delete_composition_ids.*' => 'required|uuid|distinct', 'apply_calculation' => 'sometimes|boolean', 'expected_calculation_signature' => 'sometimes|string|size:64|regex:/^[a-f0-9]+$/'];
        foreach (config('process-flows.fields') as $key => $field) {
            $rules['verification.'.$key] = 'sometimes|boolean';
            if ($field['type'] === 'number') {
                $rules['attributes.'.$key] = 'sometimes|nullable|numeric|min:'.$field['min'].'|max:'.$field['max'].(in_array($key, ['density', 'molecular_weight'], true) ? '|gt:0' : '');
            } elseif ($field['type'] === 'choice') {
                $rules['attributes.'.$key] = ['sometimes', 'nullable', Rule::in($field['options'])];
            } else {
                $rules['attributes.'.$key] = 'sometimes|nullable|string|max:'.$field['max'];
            }
        }
        $rules += ['screening' => 'sometimes|array:values,comments,thresholds', 'screening.values' => 'sometimes|array',
            'screening.comments' => 'sometimes|array|max:117', 'screening.comments.*' => 'nullable|string|max:4000',
            'screening.thresholds' => 'sometimes|array|max:88', 'screening.thresholds.*' => 'nullable|array:threshold,near',
            'screening.thresholds.*.threshold' => 'sometimes|array|list|min:1|max:2', 'screening.thresholds.*.threshold.*' => 'required|numeric|min:-1000000000000|max:1000000000000',
            'screening.thresholds.*.near' => 'sometimes|array|list|max:2', 'screening.thresholds.*.near.*' => 'required|numeric|min:0|max:1000000000000'];
        foreach (app(FlowScreening::class)->schema()['fields'] as $field) {
            $rules['screening.values.'.$field['id']] = 'sometimes|nullable|'.($field['type'] === 'boolean' ? 'boolean' : 'numeric|min:'.$field['min'].'|max:'.$field['max']);
        }
        $rules['screening.values'] = ['sometimes', 'array:'.implode(',', array_column(app(FlowScreening::class)->schema()['fields'], 'id'))];

        return $rules;
    }
}
