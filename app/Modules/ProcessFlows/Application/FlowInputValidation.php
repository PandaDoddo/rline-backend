<?php

namespace App\Modules\ProcessFlows\Application;

use App\Modules\ProcessFlows\Domain\FlowScreening;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Shared HTTP/maintenance contract; locale conversion never supplies missing facts. */
final class FlowInputValidation
{
    public function card(array $input): array
    {
        return Validator::make($this->normalize($input), $this->cardRules())->validate();
    }

    public function node(array $input): array
    {
        return Validator::make($this->normalize($input), $this->nodeRules())->validate();
    }

    public function nodeRules(): array
    {
        return ['structure_version' => 'required|integer|min:1', 'name' => 'required|string|min:1|max:255', 'code' => 'sometimes|nullable|string|max:80', 'kind' => 'required|in:folder,flow',
            'category' => ['required', Rule::in(array_keys(config('process-flows.categories')))], 'parent_id' => 'sometimes|nullable|uuid',
            'medium_kind' => ['sometimes', Rule::in(array_keys(config('process-flows.media')))]];
    }

    public function normalize(array $input): array
    {
        $input = $this->trim($input);
        if (is_array($input['attributes'] ?? null)) {
            foreach ($input['attributes'] as $key => $value) {
                if (config('process-flows.fields.'.$key.'.type') === 'number') {
                    $input['attributes'][$key] = $this->number($value);
                }
            }
        }
        if (is_array($input['composition'] ?? null)) {
            foreach ($input['composition'] as $index => $line) {
                if (is_array($line) && array_key_exists('amount', $line)) {
                    $input['composition'][$index]['amount'] = $this->number($line['amount']);
                }
            }
        }
        if (is_array($input['screening'] ?? null) && is_array($input['screening']['values'] ?? null)) {
            foreach ($input['screening']['values'] as $key => $value) {
                $input['screening']['values'][$key] = $this->number($value);
            }
        }

        return $input;
    }

    private function trim(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->trim(...), $value);
        }
        if (! is_string($value)) {
            return $value;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function number(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $normalized = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $value);
        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/iD', $normalized) && is_finite((float) $normalized)) {
            return (float) $normalized;
        }

        return $value;
    }

    public function cardRules(): array
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
