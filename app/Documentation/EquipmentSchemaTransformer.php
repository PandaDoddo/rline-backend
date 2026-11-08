<?php

namespace App\Documentation;

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
        }
        $passport->setRequired($required);
        foreach ($document->components->schemas as $name => $schema) {
            if (! $schema->type instanceof ObjectType) {
                continue;
            }
            $type = $schema->type;
            $class = class_basename($name);
            if (in_array($class, ['EquipmentRequest', 'UpdateEquipmentRequest', 'EquipmentResource', 'DiagnosticScheduleResource'], true)) {
                $type->addProperty('passport', $passport);
            }
            if ($class === 'DiagnosticScheduleResource') {
                $summary = (new ObjectType)->addProperty('kind', new StringType)
                    ->addProperty('performed_on', (new StringType)->format('date')->nullable(true))
                    ->addProperty('planned_on', (new StringType)->format('date')->nullable(true))->setRequired(['kind', 'performed_on', 'planned_on']);
                $type->addProperty('diagnostics', (new ArrayType)->setItems($summary));
            }
            if ($class === 'EquipmentRequest' || $class === 'UpdateEquipmentRequest') {
                foreach ($class === 'EquipmentRequest' ? ['version', 'checks', 'status'] : ['checks', 'profile_code'] as $field) {
                    unset($type->properties[$field]);
                    $type->setRequired(array_values(array_diff($type->required, [$field])));
                }
            }
            if ($class === 'UpdateEquipmentRequest') {
                $type->addProperty('verification', $verification);
            }
            if ($class === 'EquipmentResource') {
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
            $stage->addProperty('inspection_id', (new StringType)->format('uuid'));
            $stage->addProperty('verified_by', (new IntegerType)->nullable(true));
            $stage->addProperty('verified_at', (new StringType)->format('date-time')->nullable(true));
            $stage->setRequired(['id', 'inspection_id', 'measured_on', 'verified', 'verified_by', 'verified_at', 'measurements']);
            $read->setRequired(['cmls', 'stages']);
            $resource->addProperty('thickness', $read);
            $resource->setRequired(array_values(array_diff($resource->required, ['thickness'])));
        }
    }
}
