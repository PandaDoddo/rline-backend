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
            'substances' => 'sometimes|array|list|max:2000', 'substances.*' => 'required|array:code,values,evidence',
            'substances.*.code' => 'required|string|max:100|distinct:strict', 'substances.*.values' => 'present|array', 'substances.*.evidence' => 'sometimes|array',
            'nodes' => 'present|array|list|max:1000', 'nodes.*' => 'required|array:id,input', 'nodes.*.id' => 'required|uuid|distinct', 'nodes.*.input' => 'required|array:name,code,kind,parent_id',
            'installations' => 'present|array|list|max:500', 'installations.*' => 'required|array:id,source_id,input,costs,management,maintenance,risk_criteria,evidence', 'installations.*.id' => 'required|uuid|distinct', 'installations.*.input' => 'required|array:name,code,parent_id,passport',
            'installations.*.risk_criteria' => 'sometimes|required|array:schema_version,values', 'installations.*.risk_criteria.schema_version' => 'required_with:installations.*.risk_criteria|string|max:100', 'installations.*.risk_criteria.values' => 'sometimes|array',
            'installations.*.maintenance' => 'sometimes|required|array:records,no_stops', 'installations.*.maintenance.no_stops' => 'sometimes|nullable|boolean',
            'installations.*.maintenance.records' => 'sometimes|array|list|max:200', 'installations.*.maintenance.records.*' => 'required|array:id,kind,values,source_reference,source_verified',
            'installations.*.maintenance.records.*.id' => 'required|uuid|distinct:strict', 'installations.*.maintenance.records.*.kind' => 'required|in:mri,repair,stop',
            'installations.*.maintenance.records.*.values' => 'present|array', 'installations.*.maintenance.records.*.source_reference' => 'sometimes|required|string|max:1000',
            'installations.*.maintenance.records.*.source_verified' => 'sometimes|nullable|boolean',
            'installations.*.source_id' => 'sometimes|required|string|max:100|distinct:strict',
            'installations.*.management' => 'sometimes|required|array:schema_version,answers,details',
            'installations.*.costs' => 'sometimes|required|array:values,sources', 'installations.*.costs.values' => 'required_with:installations.*.costs|array',
            'installations.*.costs.sources' => 'sometimes|array', 'installations.*.evidence' => 'sometimes|array',
            'flow_nodes' => 'sometimes|array|list|max:5000', 'flow_nodes.*' => 'required|array:id,installation_id,source_id,input,card,evidence',
            'flow_nodes.*.id' => 'required|uuid|distinct:strict', 'flow_nodes.*.installation_id' => 'required|uuid', 'flow_nodes.*.source_id' => 'required|string|max:200|distinct:strict',
            'flow_nodes.*.input' => 'required|array:name,code,kind,category,parent_id,medium_kind',
            'flow_nodes.*.card' => 'sometimes|array:basis,attributes,sources,composition,screening',
            'flow_nodes.*.evidence' => 'sometimes|array',
            'equipment' => 'required|array|list|min:1|max:1000', 'equipment.*' => 'required|array:id,source_id,create,components,inspections,thickness,visual_inspections,inspection_categories,mechanisms,operating_scopes,flow_selection,flow_contacts,evidence,generated_passport_fields,generated_component_fields,generated_cml_counts,generated_scope_fields',
            'equipment.*.mechanisms' => 'sometimes|required|array:schema_version,inputs,component_inputs',
            'equipment.*.inspection_categories' => 'sometimes|required|array:stages',
            'equipment.*.inspection_categories.stages' => 'sometimes|array|list|max:3500',
            'equipment.*.inspection_categories.stages.*' => 'required|array:id,group_code,source_date,observations',
            'equipment.*.inspection_categories.stages.*.id' => 'required|uuid|distinct',
            'equipment.*.inspection_categories.stages.*.group_code' => 'required|in:thin,ext,cui,cl,scc,hic,aus',
            'equipment.*.inspection_categories.stages.*.source_date' => 'required|date_format:Y-m-d|after_or_equal:0001-01-01|before_or_equal:9999-12-31',
            'equipment.*.inspection_categories.stages.*.observations' => 'sometimes|array|list|max:2000',
            'equipment.*.visual_inspections' => 'sometimes|required|array:stages,insulation_overrides',
            'equipment.*.visual_inspections.stages' => 'sometimes|array|list|max:500',
            'equipment.*.visual_inspections.stages.*' => 'required|array:id,source_date,measured_on,source_verified,observations',
            'equipment.*.visual_inspections.stages.*.id' => 'required|uuid|distinct',
            'equipment.*.visual_inspections.stages.*.source_date' => 'required|date_format:Y-m-d|after_or_equal:0001-01-01|before_or_equal:9999-12-31',
            'equipment.*.visual_inspections.stages.*.measured_on' => 'present|nullable|date_format:Y-m-d|after_or_equal:0001-01-01|before_or_equal:9999-12-31',
            'equipment.*.visual_inspections.stages.*.source_verified' => 'sometimes|nullable|boolean',
            'equipment.*.visual_inspections.stages.*.observations' => 'sometimes|array|list|max:2000',
            'equipment.*.visual_inspections.insulation_overrides' => 'sometimes|array|list|max:2000',
            'equipment.*.flow_selection' => 'sometimes|array|list|max:5000', 'equipment.*.flow_contacts' => 'sometimes|array|list|max:2000',
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
            'issues' => 'sometimes|array|list|max:20000', 'issues.*' => 'required|array:source_id,flow_source_id,installation_source_id,source_path,kind,severity,source_value,message',
            'issues.*.installation_source_id' => 'sometimes|nullable|string|max:100',
            'issues.*.flow_source_id' => 'sometimes|nullable|string|max:200',
            'issues.*.source_id' => 'sometimes|nullable|string|max:100', 'issues.*.source_path' => 'required|string|max:1000',
            'issues.*.kind' => 'required|in:invalid_input,unresolved_reference,unresolved_skip,excluded_derived,source_unknown,source_generated,unsupported',
            'issues.*.severity' => 'required|in:info,warning,error', 'issues.*.source_value' => 'sometimes|nullable', 'issues.*.message' => 'required|string|max:5000',
        ])->validate();
    }
}
