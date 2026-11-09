<?php

namespace App\Modules\DataExchange\Application;

use Illuminate\Support\Facades\Validator;

final class DatasetManifest
{
    public function validate(array $manifest): array
    {
        // Laravel distinct on a nested wildcard compares all expanded records.
        // Field names may repeat across equipment/scopes; only each local list is unique.
        $uniqueList = function ($attribute, $value, $fail) {
            if (! is_array($value)) {
                return;
            }
            $seen = [];
            foreach ($value as $item) {
                if (in_array($item, $seen, true)) {
                    $fail('В списке одного объекта не должно быть повторов.');

                    return;
                }$seen[] = $item;
            }
        };

        return Validator::make($manifest, [
            'format' => 'required|in:rline-dataset-v1', 'source' => 'required|array:kind,version,label,snapshot_at,checksums',
            'source.kind' => 'required|in:stored_snapshot,demo_expanded', 'source.version' => 'required|string|max:100', 'source.label' => 'required|string|max:255', 'source.snapshot_at' => 'required|date',
            'source.checksums' => 'required|array|min:1|max:100', 'source.checksums.*' => 'required|string|regex:/^[0-9a-f]{64}$/D',
            'nodes' => 'present|array|list|max:1000', 'nodes.*' => 'required|array:id,input', 'nodes.*.id' => 'required|uuid|distinct', 'nodes.*.input' => 'required|array:name,code,kind,parent_id',
            'installations' => 'present|array|list|max:500', 'installations.*' => 'required|array:id,input', 'installations.*.id' => 'required|uuid|distinct', 'installations.*.input' => 'required|array:name,code,parent_id,passport',
            'equipment' => 'required|array|list|min:1|max:1000', 'equipment.*' => 'required|array:id,source_id,create,components,inspections,thickness,operating_scopes,evidence,generated_passport_fields,generated_component_fields,generated_cml_counts,generated_scope_fields',
            'equipment.*.generated_component_fields' => 'sometimes|array|list|max:2000',
            'equipment.*.generated_component_fields.*' => 'required|array:component_id,field',
            'equipment.*.generated_component_fields.*.component_id' => 'required|uuid',
            'equipment.*.generated_component_fields.*.field' => 'required|in:nominal_thickness_mm,rejection_passport_mm,diameter_mm,length_mm',
            'equipment.*.generated_cml_counts' => 'sometimes|array|list|max:500',
            'equipment.*.generated_cml_counts.*' => 'required|uuid|distinct:strict',
            'equipment.*.generated_scope_fields' => 'sometimes|array|list|max:100',
            'equipment.*.generated_scope_fields.*' => 'required|array:kind,component_id,fields',
            'equipment.*.generated_scope_fields.*.kind' => 'required|in:coil,tube',
            'equipment.*.generated_scope_fields.*.component_id' => 'present|nullable|uuid',
            'equipment.*.generated_scope_fields.*.fields' => ['required', 'array', 'list', 'min:1', 'max:30', $uniqueList],
            'equipment.*.generated_scope_fields.*.fields.*' => 'required|string|max:64',
            'equipment.*.generated_passport_fields' => ['sometimes', 'array', 'list', 'max:200', $uniqueList],
            'equipment.*.generated_passport_fields.*' => 'required|string|max:100',
            'equipment.*.id' => 'required|uuid|distinct', 'equipment.*.source_id' => 'required|string|max:100|distinct:strict', 'equipment.*.create' => 'required|array:installation_id,profile_code,passport,passport_qualifiers',
            'equipment.*.components' => 'sometimes|array|list|max:500', 'equipment.*.operating_scopes' => 'sometimes|array|list|max:100', 'equipment.*.evidence' => 'sometimes|array',
            'equipment.*.inspections' => 'sometimes|array|list|max:500', 'equipment.*.inspections.*' => 'required|array:source_reference,requested_id,input',
            'equipment.*.inspections.*.source_reference' => 'required|string|max:1000', 'equipment.*.inspections.*.requested_id' => 'required|uuid|distinct', 'equipment.*.inspections.*.input' => 'required|array:kind,is_primary,planned_on,performed_on,report_number,notes,next_on,organization,inspection_type,follow_up_dates,unknown_fields',
            'equipment.*.thickness' => 'sometimes|array:cmls,stages', 'equipment.*.thickness.cmls' => 'sometimes|array|list|max:500', 'equipment.*.thickness.stages' => 'sometimes|array|list|max:500',
            'equipment.*.thickness.stages.*' => 'required|array:source_date,measured_on,source_verified,measurements',
            'equipment.*.thickness.stages.*.source_date' => ['required', 'string', 'max:20', function ($attribute, $value, $fail) {
                if ($value === '__prim') {
                    return;
                }
                $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if (! $date || $date->format('Y-m-d') !== $value) {
                    $fail('Укажите исходную дату ГГГГ-ММ-ДД либо __prim.');
                }
            }], 'equipment.*.thickness.stages.*.measured_on' => 'present|nullable|date_format:Y-m-d',
            'equipment.*.thickness.stages.*.source_verified' => 'sometimes|boolean', 'equipment.*.thickness.stages.*.measurements' => 'sometimes|array|list|max:500',
            'equipment.*.thickness.stages.*.measurements.*' => 'required|array:cml_id,points,point_origins',
            'equipment.*.thickness.stages.*.measurements.*.cml_id' => 'required|uuid',
            'equipment.*.thickness.stages.*.measurements.*.points' => 'required|array|list|min:1|max:100',
            'equipment.*.thickness.stages.*.measurements.*.point_origins' => 'sometimes|array|list|min:1|max:100',
            'equipment.*.thickness.stages.*.measurements.*.point_origins.*' => 'required|in:stored_snapshot,demo_generated',
            'issues' => 'sometimes|array|list|max:20000', 'issues.*' => 'required|array:source_id,source_path,kind,severity,source_value,message',
            'issues.*.source_id' => 'sometimes|nullable|string|max:100', 'issues.*.source_path' => 'required|string|max:1000',
            'issues.*.kind' => 'required|in:invalid_input,unresolved_reference,unresolved_skip,excluded_derived,source_unknown,source_generated,unsupported',
            'issues.*.severity' => 'required|in:info,warning,error', 'issues.*.source_value' => 'sometimes|nullable', 'issues.*.message' => 'required|string|max:5000',
        ])->validate();
    }
}
