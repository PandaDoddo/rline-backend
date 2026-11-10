<?php

namespace App\Modules\Diagnostics\Application;

use App\Modules\Diagnostics\Domain\InspectionCategorySchema;

final class InspectionCategoryInputValidation
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
            foreach (is_array($stage['observations'] ?? null) ? $stage['observations'] : [] as $row => $observation) {
                if (! is_array($observation)) {
                    continue;
                }
                if (is_string($observation['component_id'] ?? null)) {
                    $input['stages'][$index]['observations'][$row]['component_id'] = strtolower($observation['component_id']);
                }
                if (in_array($observation['cracks_present'] ?? null, [0, 1, '0', '1'], true)) {
                    $input['stages'][$index]['observations'][$row]['cracks_present'] = (bool) (int) $observation['cracks_present'];
                }
            }
            if (is_array($stage['reset_components'] ?? null)) {
                $input['stages'][$index]['reset_components'] = array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $stage['reset_components']);
            }
        }
        if (is_array($input['delete_stage_ids'] ?? null)) {
            $input['delete_stage_ids'] = array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $input['delete_stage_ids']);
        }

        return $input;
    }

    public static function rules(): array
    {
        $p = 'inspection_categories';

        return [$p => 'sometimes|required|array:schema_version,stages,delete_stage_ids', "$p.schema_version" => 'required_with:inspection_categories|string|max:100',
            "$p.stages" => 'sometimes|array|list|max:3500', "$p.stages.*" => 'required|array:id,group_code,inspection_id,reference_date,observations,reset_components',
            "$p.stages.*.id" => 'required|uuid|distinct', "$p.stages.*.group_code" => 'required|in:'.implode(',', array_keys(InspectionCategorySchema::groups())),
            "$p.stages.*.inspection_id" => 'sometimes|nullable|uuid', "$p.stages.*.reference_date" => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:0001-01-01|before_or_equal:9999-12-31',
            "$p.stages.*.observations" => 'sometimes|array|list|max:2000', "$p.stages.*.observations.*" => 'required|array:component_id,category,cracks_present',
            "$p.stages.*.observations.*.component_id" => 'required|uuid', "$p.stages.*.observations.*.category" => 'sometimes|nullable|in:A,B,C,D,E,none',
            "$p.stages.*.observations.*.cracks_present" => 'sometimes|nullable|boolean',
            "$p.stages.*.reset_components" => 'sometimes|array|list|max:2000', "$p.stages.*.reset_components.*" => 'required|uuid',
            "$p.delete_stage_ids" => 'sometimes|array|list|max:3500', "$p.delete_stage_ids.*" => 'required|uuid|distinct'];
    }
}
