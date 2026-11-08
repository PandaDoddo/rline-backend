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

class MaterialCostSchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $values = (new ObjectType)->addProperty('name', new StringType)->addProperty('factor', (new NumberType)->nullable(true));
        $sourceInput = (new ObjectType)->addProperty('citation', (new StringType)->nullable(true))->addProperty('conditions', (new StringType)->nullable(true));
        $source = $sourceInput->clone()->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['citation', 'conditions', 'by', 'at']);
        $check = (new ObjectType)->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['by', 'at']);
        foreach ($document->components->schemas as $name => $schema) {
            $type = $schema->type;
            if (! $type instanceof ObjectType) {
                continue;
            }
            if (class_basename($name) === 'MaterialCostResource') {
                $type->addProperty('values', $values->clone()->setRequired(['name', 'factor']));
                $type->addProperty('base_values', $values->clone());
                $type->addProperty('overrides', $values->clone());
                $type->addProperty('group', (new StringType)->enum(['base', 'clad', 'coat', 'lin']));
                $checks = new ObjectType;
                $checks->additionalProperties = $check;
                $sources = new ObjectType;
                $sources->additionalProperties = $source;
                $type->addProperty('sources', $sources)->addProperty('checks', $checks);
            }
            if (class_basename($name) === 'UpdateMaterialCostRequest') {
                $type->addProperty('values', $values->clone());
                $sources = new ObjectType;
                foreach (['name', 'factor'] as $field) {
                    $sources->addProperty($field, $sourceInput->clone());
                }
                $type->addProperty('sources', $sources)->setRequired(['version']);
                foreach (['base_values', 'base_raw', 'status', 'material_group', 'english_name', 'checks'] as $key) {
                    unset($type->properties[$key]);
                }
            }
            if (in_array(class_basename($name), ['SteelGradeResource', 'SteelCostResource'], true)) {
                $cost = (new ObjectType)->addProperty('factor', (new NumberType)->nullable(true))->addProperty('reference_id', (new StringType)->nullable(true))
                    ->addProperty('reference_version', (new IntegerType)->nullable(true))->addProperty('reference_base_version', (new StringType)->nullable(true))
                    ->addProperty('analog', (new StringType)->nullable(true))->addProperty('reason', new StringType)->addProperty('warning', new BooleanType)
                    ->addProperty('assumptions', (new ArrayType)->setItems(new StringType))->setRequired(['factor', 'reference_id', 'reference_version', 'reference_base_version', 'analog', 'reason', 'warning', 'assumptions']);
                $cost->addProperty('automatic_factor', (new NumberType)->nullable(true))->addProperty('manual', new BooleanType)->setRequired([...$cost->required, 'automatic_factor', 'manual']);
                if (class_basename($name) === 'SteelGradeResource') {
                    $type->properties['assessment']->addProperty('material_cost', $cost);
                } else {
                    $type->addProperty('cost', $cost);
                    $type->addProperty('subtype', (new StringType)->nullable(true))->addProperty('refine', (new StringType)->nullable(true));
                    $overrides = (new ObjectType)->addProperty('accepted_cost', (new NumberType)->nullable(true))->addProperty('cost_reason', (new StringType)->nullable(true));
                    $type->addProperty('overrides', $overrides);
                    $checks = new ObjectType;
                    $checks->additionalProperties = $check;
                    $sources = new ObjectType;
                    $sources->additionalProperties = $source;
                    $type->addProperty('sources', $sources)->addProperty('checks', $checks);
                }
            }
            if (class_basename($name) === 'UpdateSteelCostRequest') {
                $type->addProperty('values', (new ObjectType)->addProperty('accepted_cost', (new NumberType)->nullable(true))->addProperty('cost_reason', (new StringType)->nullable(true)))->setRequired(['version']);
                $sources = new ObjectType;
                foreach (['accepted_cost', 'cost_reason'] as $field) {
                    $sources->addProperty($field, $sourceInput->clone());
                }
                $type->addProperty('sources', $sources);
            }
        }
    }
}
