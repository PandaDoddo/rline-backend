<?php

namespace App\Documentation;

use App\Modules\Equipment\Application\ComponentHistory;
use App\Modules\Equipment\Domain\ComponentConstructionSchema;
use App\Modules\Equipment\Domain\ComponentDetailSchema;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class EquipmentSchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $passport = new ObjectType;
        $verification = new ObjectType;
        $qualifiers = new ObjectType;
        $required = [];
        foreach (config('equipment.passport') as $key => $field) {
            $property = ($field['type'] ?? '') === 'number' ? new NumberType : new StringType;
            $property->setDescription($field['label']);
            $property->nullable(str_contains($field['rules'], 'nullable'));
            if (isset($field['options'])) {
                $property->enum($field['options']);
            }
            if (($field['type'] ?? '') === 'date') {
                $property->format('date');
            }
            if ($property instanceof NumberType) {
                if (preg_match('/min:([\d.-]+)/', $field['rules'], $matches)) {
                    $property->setMin((float) $matches[1]);
                }
                if (preg_match('/max:([\d.-]+)/', $field['rules'], $matches)) {
                    $property->setMax((float) $matches[1]);
                }
            }
            if (str_contains($field['rules'], 'required')) {
                $required[] = $key;
            }
            $passport->addProperty($key, $property);
            $verification->addProperty($key, new BooleanType);
            $qualifier = (new ObjectType)->addProperty('state', (new StringType)->enum($key === 'name' ? ['unknown'] : ['unknown', 'not_applicable', 'not_present']));
            if (($field['type'] ?? '') === 'date') {
                $qualifier->addProperty('precision', (new StringType)->enum(['day', 'year']));
            }
            $qualifiers->addProperty($key, $qualifier);
        }
        $passport->setRequired($required);
        foreach ($document->components->schemas as $name => $schema) {
            if (class_basename($name) === 'MechanismElementResource') {
                $schema->type = MechanismApiSchema::make()->properties['elements']->items;

                continue;
            }
            if (! $schema->type instanceof ObjectType) {
                continue;
            }
            $type = $schema->type;
            $class = class_basename($name);
            if ($class === 'EquipmentResource') {
                $type->addProperty('mechanisms', MechanismApiSchema::make());
                $type->addProperty('inspection_categories', InspectionCategoryApiSchema::make());
                $type->addProperty('visual_inspections', VisualInspectionApiSchema::make());
                $type->setRequired(array_values(array_diff($type->required, ['visual_inspections', 'inspection_categories', 'mechanisms'])));
                $origin = (new ObjectType)->addProperty('batch_id', (new StringType)->format('uuid'))->addProperty('kind', (new StringType)->enum(['stored_snapshot', 'demo_expanded']))
                    ->addProperty('label', new StringType)->addProperty('source_id', new StringType)->addProperty('source_snapshot_at', (new StringType)->format('date-time'))
                    ->addProperty('applied_at', (new StringType)->format('date-time'))->addProperty('imported_version', new IntegerType)->addProperty('changed_since_import', new BooleanType)->addProperty('source_issue_count', new IntegerType)
                    ->addProperty('generated_passport_fields', (new ArrayType)->setItems(new StringType));
                $childOrigin = (new ObjectType)->addProperty('id', (new StringType)->format('uuid'))->addProperty('fields', (new ArrayType)->setItems(new StringType))->setRequired(['id', 'fields']);
                $origin->addProperty('generated_component_fields', (new ArrayType)->setItems($childOrigin));
                $origin->addProperty('generated_scope_fields', (new ArrayType)->setItems($childOrigin->clone()));
                $origin->addProperty('generated_cml_counts', (new ArrayType)->setItems((new StringType)->format('uuid')));
                $origin->setRequired(array_keys($origin->properties));
                $type->addProperty('data_origin', $origin->nullable(true));
            }
            if ($class === 'ComponentHistoryResource') {
                $type->addProperty('id', (new StringType)->format('uuid'));
                $type->addProperty('kind', (new StringType)->enum(ComponentHistory::KINDS));
                $type->addProperty('occurred_on', (new StringType)->format('date')->nullable(true));
                $type->addProperty('recorded_at', (new StringType)->format('date-time'));
                $type->addProperty('recorded_by', new IntegerType);
                foreach (['actor_name', 'source_reference'] as $field) {
                    $type->addProperty($field, (new StringType)->nullable(true));
                }
                $type->addProperty('description', new StringType);
                $type->setRequired(['id', 'kind', 'occurred_on', 'actor_name', 'description', 'source_reference', 'recorded_by', 'recorded_at']);
            }
            if ($class === 'EquipmentComponentResource') {
                $type->addProperty('assemblies', ComponentAssemblySchema::make());
                $type->addProperty('connections', ComponentConnectionSchema::make());
                $type->addProperty('history_count', new IntegerType);
                $type->addProperty('type_code', (new StringType)->nullable(true));
                $type->addProperty('coil_role', (new StringType)->enum(['inlet', 'inlet_boss', 'body', 'outlet_boss', 'outlet'])->nullable(true));
                $port = (new ObjectType)->addProperty('id', (new StringType)->format('uuid'))->addProperty('direction', (new StringType)->enum(['inlet', 'outlet']));
                foreach (['mark', 'service_name', 'connection_kind'] as $field) {
                    $port->addProperty($field, (new StringType)->nullable(true));
                }
                $port->setRequired(['id', 'direction', 'mark', 'service_name', 'connection_kind']);
                $type->addProperty('ports', (new ArrayType)->setItems($port));
                $type->setRequired(array_values(array_unique([...$type->required, 'type_code', 'coil_role', 'construction', 'ports', 'connections', 'assemblies', 'history_count', ...array_keys(ComponentDetailSchema::BLOCKS)])));
                $construction = new ObjectType;
                foreach (ComponentConstructionSchema::LABELS as $field => $label) {
                    $property = match ($field) {
                        'is_primary' => new BooleanType,
                        'nominal_pressure_mpa' => new NumberType,
                        default => new StringType,
                    };
                    $property->nullable(true)->setDescription($label);
                    if (isset(ComponentConstructionSchema::OPTIONS[$field])) {
                        $property->enum(ComponentConstructionSchema::OPTIONS[$field]);
                    }
                    $construction->addProperty($field, $property);
                }
                $construction->setRequired(array_keys(ComponentConstructionSchema::LABELS));
                $type->addProperty('construction', $construction->nullable(true));
                foreach (ComponentDetailSchema::BLOCKS as $block => $fields) {
                    $geometry = new ObjectType;
                    foreach ($fields as $field => [$label, $fieldType]) {
                        $property = match ($fieldType) {
                            'boolean' => new BooleanType,
                            'positive', 'angle' => new NumberType,
                            'integer' => new IntegerType,
                            default => new StringType,
                        };
                        $property->nullable(true)->setDescription($label);
                        if (isset(ComponentDetailSchema::OPTIONS[$field])) {
                            $property->enum(ComponentDetailSchema::OPTIONS[$field]);
                        }
                        $geometry->addProperty($field, $property);
                    }
                    $geometry->setRequired(array_keys($fields));
                    $type->addProperty($block, $geometry->nullable(true));
                }
            }
            if (in_array($class, ['EquipmentRequest', 'UpdateEquipmentRequest', 'EquipmentResource', 'DiagnosticScheduleResource'], true)) {
                $type->addProperty('passport', $passport);
                $type->addProperty('passport_qualifiers', $qualifiers);
            }
            if (in_array($class, ['DiagnosticScheduleResource', 'EquipmentResource'], true)) {
                $summary = (new ObjectType)->addProperty('kind', new StringType)
                    ->addProperty('performed_on', (new StringType)->format('date')->nullable(true))
                    ->addProperty('planned_on', (new StringType)->format('date')->nullable(true))
                    ->addProperty('absorbed_by', (new StringType)->nullable(true))
                    ->addProperty('absorbed_on', (new StringType)->format('date')->nullable(true))
                    ->addProperty('performed_by', (new StringType)->nullable(true))->setRequired(['kind', 'performed_on', 'planned_on', 'absorbed_by', 'absorbed_on', 'performed_by']);
                $type->addProperty('diagnostics', (new ArrayType)->setItems($summary));
            }
            if ($class === 'EquipmentRequest' || $class === 'UpdateEquipmentRequest') {
                foreach ($class === 'EquipmentRequest' ? ['version', 'checks', 'status'] : ['checks', 'profile_code'] as $field) {
                    unset($type->properties[$field]);
                    $type->setRequired(array_values(array_diff($type->required, [$field])));
                }
            }
            if ($class === 'UpdateEquipmentRequest') {
                $type->addProperty('mechanisms', MechanismApiSchema::make(true));
                $type->addProperty('inspection_categories', InspectionCategoryApiSchema::make(true));
                $type->addProperty('visual_inspections', VisualInspectionApiSchema::make(true));
                $type->setRequired(['installation_id', 'passport', 'version']);
                $type->addProperty('verification', $verification);
                $type->addProperty('operating_scopes', OperatingScopeSchema::make(true));
            }
            if ($class === 'EquipmentResource') {
                $type->addProperty('operating_scopes', OperatingScopeSchema::make());
                $type->setRequired(array_values(array_diff($type->required, ['operating_scopes'])));
                $type->addProperty('flows', EquipmentFlowSchema::make());
                $type->setRequired(array_values(array_diff($type->required, ['flows'])));
                $type->addProperty('condition', CorrosionSchema::make());
                $type->setRequired(array_values(array_diff($type->required, ['condition'])));
                $type->setRequired(array_values(array_diff($type->required, ['components', 'inspections'])));
                $check = (new ObjectType)->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['by', 'at']);
                $checks = new ObjectType;
                $checks->additionalProperties = $check;
                $type->addProperty('checks', $checks);
            }
        }
        $input = null;
        $resource = null;
        foreach ($document->components->schemas as $name => $schema) {
            if (class_basename($name) === 'UpdateEquipmentRequest') {
                $input = $schema->type->properties['thickness'] ?? null;
            }
            if (class_basename($name) === 'EquipmentResource') {
                $resource = $schema->type;
            }
        }
        if ($input instanceof ObjectType && $resource instanceof ObjectType) {
            // Reuse validation-derived point shapes; add only server-owned identifiers and verification metadata.
            $read = $input->clone();
            $cml = $read->properties['cmls']->items;
            $cml->addProperty('code', new StringType);
            $cml->setRequired(['id', 'component_id', 'code', 'point_count', 'notes']);
            $stage = $read->properties['stages']->items;
            $stage->properties['measurements']->items->addProperty('point_origins', (new ArrayType)->setItems((new StringType)->enum(['manual', 'stored_snapshot', 'demo_generated'])));
            $stage->addProperty('inspection_id', (new StringType)->format('uuid')->nullable(true));
            $stage->addProperty('verified_by', (new IntegerType)->nullable(true));
            $stage->addProperty('verified_at', (new StringType)->format('date-time')->nullable(true));
            $stage->setRequired(['id', 'inspection_id', 'origin', 'measured_on', 'verified', 'verified_by', 'verified_at', 'measurements']);
            $read->setRequired(['cmls', 'stages']);
            $resource->addProperty('thickness', $read);
            $resource->setRequired(array_values(array_diff($resource->required, ['thickness'])));
        }
    }
}
