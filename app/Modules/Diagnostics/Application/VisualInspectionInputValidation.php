<?php

namespace App\Modules\Diagnostics\Application;

use App\Modules\Diagnostics\Domain\VisualInspectionSchema;

final class VisualInspectionInputValidation
{
    public function normalize(array $input): array
    {
        foreach (is_array($input['stages'] ?? null) ? $input['stages'] : [] as $index => $stage) {
            if (! is_array($stage)) {
                continue;
            }
            foreach (['id', 'inspection_id'] as $key) {
                if (is_string($stage[$key] ?? null)) {
                    $input['stages'][$index][$key] = strtolower($stage[$key]);
                }
            }
            if (is_array($stage['reset_components'] ?? null)) {
                $input['stages'][$index]['reset_components'] = array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $stage['reset_components']);
            }
            foreach (['verified', 'unlock'] as $key) {
                if (array_key_exists($key, $stage)) {
                    $input['stages'][$index][$key] = $this->boolean($stage[$key]);
                }
            }
            foreach (is_array($stage['observations'] ?? null) ? $stage['observations'] : [] as $row => $observation) {
                if (! is_array($observation)) {
                    continue;
                }
                if (is_string($observation['component_id'] ?? null)) {
                    $input['stages'][$index]['observations'][$row]['component_id'] = strtolower($observation['component_id']);
                }
                foreach (is_array($observation['values'] ?? null) ? $observation['values'] : [] as $key => $value) {
                    $field = VisualInspectionSchema::fields()[$key] ?? null;
                    if (! $field) {
                        continue;
                    }
                    if (is_string($value)) {
                        $value = trim($value) === '' ? null : trim($value);
                    }
                    if ($field['type'] === 'boolean') {
                        $value = $this->boolean($value);
                    } elseif ($field['type'] === 'number' && is_string($value)) {
                        $number = str_replace([' ', "\u{00a0}", "\u{202f}", ','], ['', '', '', '.'], $value);
                        $value = is_numeric($number) ? $number + 0 : $value;
                    }
                    $input['stages'][$index]['observations'][$row]['values'][$key] = $value;
                }
            }
        }
        foreach (is_array($input['insulation_overrides'] ?? null) ? $input['insulation_overrides'] : [] as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (is_string($entry['component_id'] ?? null)) {
                $input['insulation_overrides'][$index]['component_id'] = strtolower($entry['component_id']);
            }
            if (array_key_exists('is_insulated', $entry)) {
                $input['insulation_overrides'][$index]['is_insulated'] = $this->boolean($entry['is_insulated']);
            }
        }

        if (is_array($input['delete_stage_ids'] ?? null)) {
            $input['delete_stage_ids'] = array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $input['delete_stage_ids']);
        }

        return $input;
    }

    private function boolean(mixed $value): mixed
    {
        return in_array($value, [0, 1, '0', '1'], true) ? (bool) (int) $value : $value;
    }

    public static function rules(): array
    {
        $prefix = 'visual_inspections';
        $rules = [$prefix => 'sometimes|required|array:schema_version,stages,delete_stage_ids,insulation_overrides',
            "$prefix.schema_version" => 'required_with:visual_inspections|string|max:100',
            "$prefix.stages" => 'sometimes|array|list|max:500', "$prefix.stages.*" => 'required|array:id,inspection_id,reference_date,measured_on,verified,unlock,observations,reset_components',
            "$prefix.stages.*.id" => 'required|uuid|distinct', "$prefix.stages.*.inspection_id" => 'sometimes|nullable|uuid',
            "$prefix.stages.*.reference_date" => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:0001-01-01|before_or_equal:9999-12-31', "$prefix.stages.*.measured_on" => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:0001-01-01|before_or_equal:9999-12-31',
            "$prefix.stages.*.verified" => 'sometimes|boolean', "$prefix.stages.*.unlock" => 'sometimes|boolean',
            "$prefix.stages.*.observations" => 'sometimes|array|list|max:2000', "$prefix.stages.*.observations.*" => 'required|array:component_id,values',
            "$prefix.stages.*.observations.*.component_id" => 'required|uuid',
            "$prefix.stages.*.observations.*.values" => 'present|array:'.implode(',', array_keys(VisualInspectionSchema::fields())),
            "$prefix.stages.*.reset_components" => 'sometimes|array|list|max:2000', "$prefix.stages.*.reset_components.*" => 'required|uuid',
            "$prefix.delete_stage_ids" => 'sometimes|array|list|max:500', "$prefix.delete_stage_ids.*" => 'required|uuid|distinct',
            "$prefix.insulation_overrides" => 'sometimes|array|list|max:2000', "$prefix.insulation_overrides.*" => 'required|array:component_id,is_insulated',
            "$prefix.insulation_overrides.*.component_id" => 'required|uuid|distinct', "$prefix.insulation_overrides.*.is_insulated" => 'present|nullable|boolean'];
        foreach (VisualInspectionSchema::fields() as $key => $field) {
            $rule = ['sometimes', 'nullable'];
            $rule = match ($field['type']) {
                'boolean' => [...$rule, 'boolean'],
                'number' => [...$rule, 'numeric', 'min:'.$field['min'], 'max:'.$field['max']],
                'choice' => [...$rule, 'string', 'in:'.implode(',', array_keys($field['options']))],
                default => [...$rule, 'string', 'max:'.$field['max_length']],
            };
            $rules["$prefix.stages.*.observations.*.values.$key"] = $rule;
        }

        return $rules;
    }
}
