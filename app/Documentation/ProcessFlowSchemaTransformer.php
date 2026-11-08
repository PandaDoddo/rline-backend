<?php

namespace App\Documentation;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class ProcessFlowSchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $attributes = new ObjectType;
        foreach (config('process-flows.fields') as $key => $field) {
            $type = $field['type'] === 'number' ? (new NumberType)->setMin($field['min'])->setMax($field['max']) : new StringType;
            if (isset($field['options'])) {
                $type->enum($field['options']);
            }
            $attributes->addProperty($key, $type->nullable(true)->setDescription($field['label']));
        }
        $sourceInput = (new ObjectType)->addProperty('citation', (new StringType)->nullable(true))->addProperty('conditions', (new StringType)->nullable(true));
        $source = $sourceInput->clone()->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['citation', 'conditions', 'by', 'at']);
        $sources = new ObjectType;
        $sources->additionalProperties = $source;
        $checks = new ObjectType;
        $checks->additionalProperties = (new ObjectType)->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['by', 'at']);
        $properties = (new ObjectType)->addProperty('molecular_weight', (new NumberType)->nullable(true))->addProperty('boiling_point', (new NumberType)->nullable(true))->addProperty('liquid_density', (new NumberType)->nullable(true))->setRequired(['molecular_weight', 'boiling_point', 'liquid_density']);
        $flags = new ObjectType;
        foreach (['flammable', 'toxic', 'nfnt', 'inert'] as $key) {
            $flags->addProperty($key, (new BooleanType)->nullable(true));
        }
        $flags->setRequired(array_keys($flags->properties));
        $physical = (new ObjectType)->addProperty('name', new StringType)->addProperty('mw', (new NumberType)->nullable(true))->addProperty('nbp', (new NumberType)->nullable(true))->addProperty('dens', (new NumberType)->nullable(true));
        // Catalog qualifiers remain explicit strings; consumers only calculate from numeric values.
        foreach (['mw', 'nbp', 'dens'] as $key) {
            $physical->properties[$key] = (new AnyOf)->setItems([(new NumberType)->nullable(true), new StringType]);
        }
        foreach (['formula', 'repEnv', 'api581cls'] as $key) {
            $physical->addProperty($key, (new StringType)->nullable(true));
        }
        foreach (['flammable', 'toxic', 'nfnt', 'inert'] as $key) {
            $physical->addProperty($key, (new BooleanType)->nullable(true));
        }
        $component = (new ObjectType)->addProperty('id', (new StringType)->format('uuid'))->addProperty('substance_id', (new StringType)->format('uuid')->nullable(true))
            ->addProperty('role', (new StringType)->enum(['main', 'impurity', 'reference']))->addProperty('name', (new StringType)->nullable(true))->addProperty('amount', (new NumberType)->nullable(true))->addProperty('unit', (new StringType)->nullable(true))
            ->addProperty('plus', new BooleanType)->addProperty('notes', (new StringType)->nullable(true))->addProperty('position', new IntegerType)->addProperty('substance_version', (new IntegerType)->nullable(true))->addProperty('substance_status', (new StringType)->nullable(true))->addProperty('substance_values', $physical->nullable(true));
        $component->setRequired(array_keys($component->properties));
        $row = (new ObjectType)->addProperty('id', (new StringType)->format('uuid'))->addProperty('substance_id', (new StringType)->format('uuid'))->addProperty('substance_version', new IntegerType)->addProperty('name', new StringType)->addProperty('flags', $flags);
        foreach (['mass_percent', 'mole_percent', 'mw', 'nbp', 'density'] as $key) {
            $row->addProperty($key, (new NumberType)->nullable(true));
        }
        $row->setRequired(array_keys($row->properties));
        $criterion = (new ObjectType)->addProperty('criterion', (new StringType)->enum(['flammable', 'toxic', 'nfnt', 'inert', 'unknown']))->addProperty('count', new IntegerType)
            ->addProperty('mass_percent', (new NumberType)->nullable(true))->addProperty('mole_percent', (new NumberType)->nullable(true))->addProperty('components', (new ArrayType)->setItems(new StringType))->setRequired(['criterion', 'count', 'mass_percent', 'mole_percent', 'components']);
        $result = (new ObjectType)->addProperty('engine_version', new StringType)->addProperty('signature', new StringType)->addProperty('input_basis', (new StringType)->enum(['mass', 'mole']))->addProperty('input_sum', new NumberType)->addProperty('complete', new BooleanType)
            ->addProperty('rows', (new ArrayType)->setItems($row))->addProperty('properties', $properties)->addProperty('criteria', (new ArrayType)->setItems($criterion))->addProperty('warnings', (new ArrayType)->setItems(new StringType));
        $result->setRequired(array_keys($result->properties));
        foreach ($document->components->schemas as $name => $schema) {
            if (class_basename($name) === 'ProcessFlowResource') {
                $schema->type = new ObjectType;
            }
            $type = $schema->type;
            if (! $type instanceof ObjectType) {
                continue;
            }
            $class = class_basename($name);
            if (in_array($class, ['ProcessFlowNodeResource', 'ProcessFlowResource'], true)) {
                $type->addProperty('parent_id', (new StringType)->format('uuid')->nullable(true))->addProperty('kind', (new StringType)->enum(['folder', 'flow']))->addProperty('category', (new StringType)->enum(array_keys(config('process-flows.categories'))))
                    ->addProperty('medium_kind', (new StringType)->enum(array_keys(config('process-flows.media'))))->addProperty('status', (new StringType)->enum(['active', 'archived']));
            }
            if ($class === 'ProcessFlowResource') {
                foreach (['id', 'installation_id', 'name'] as $key) {
                    $type->addProperty($key, new StringType);
                }
                $type->addProperty('version', new IntegerType)->addProperty('children_count', new IntegerType)->addProperty('basis', (new StringType)->enum(['mass', 'mole']))->addProperty('attributes', $attributes->clone())->addProperty('sources', $sources)->addProperty('checks', $checks)
                    ->addProperty('composition', (new ArrayType)->setItems($component))->addProperty('proposed_calculation', $result->clone()->nullable(true))
                    ->addProperty('accepted_calculation', (new ObjectType)->addProperty('id', (new StringType)->format('uuid'))->addProperty('signature', new StringType)->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->addProperty('properties', $properties->clone())->setRequired(['id', 'signature', 'by', 'at', 'properties'])->nullable(true))->addProperty('calculation_stale', new BooleanType);
                $type->setRequired(array_keys($type->properties));
            }
            if ($class === 'ProcessFlowResource') {
                $type->addProperty('path', new StringType);
            }
            if ($class === 'ProcessFlowCalculationResource') {
                $type->addProperty('inputs', (new ObjectType)->addProperty('basis', (new StringType)->enum(['mass', 'mole']))->addProperty('components', (new ArrayType)->setItems($component->clone()))->setRequired(['basis', 'components']))->addProperty('result', $result->clone());
            }
            if ($class === 'UpdateProcessFlowRequest') {
                $type->addProperty('attributes', $attributes->clone());
                $inputSources = new ObjectType;
                foreach (array_keys(config('process-flows.fields')) as $key) {
                    $inputSources->addProperty($key, $sourceInput->clone());
                }
                $type->addProperty('sources', $inputSources)->setRequired(['version']);
            }
            if ($class === 'UpdateProcessFlowNodeRequest') {
                foreach (['kind', 'category', 'medium_kind'] as $key) {
                    unset($type->properties[$key]);
                    $type->setRequired(array_values(array_diff($type->required, [$key])));
                }
            }
        }
    }
}
