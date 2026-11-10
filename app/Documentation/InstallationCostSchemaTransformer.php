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

class InstallationCostSchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $values = new ObjectType;
        foreach (config('installation-costs.fields') as $key => $field) {
            $value = $field['type'] === 'number' ? (new NumberType)->setMin(0)->setMax($field['max']) : new StringType;
            $values->addProperty($key, $value->nullable(true)->setDescription($field['label'].', '.$field['unit']));
        }
        $sourceInput = (new ObjectType)->addProperty('citation', (new StringType)->nullable(true))->addProperty('conditions', (new StringType)->nullable(true));
        $source = $sourceInput->clone()->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['citation', 'conditions', 'by', 'at']);
        $check = (new ObjectType)->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['by', 'at']);
        foreach ($document->components->schemas as $name => $schema) {
            $type = $schema->type;
            if (! $type instanceof ObjectType) {
                continue;
            }
            if (class_basename($name) === 'InstallationCostResource') {
                $type->addProperty('data_origin', ImportOriginSchema::make()->nullable(true));
                $type->addProperty('values', $values->clone());
                $checks = new ObjectType;
                $checks->additionalProperties = $check;
                $sources = new ObjectType;
                $sources->additionalProperties = $source;
                $type->addProperty('sources', $sources)->addProperty('checks', $checks);
                $derived = (new ObjectType)->addProperty('reference_version', new StringType)->addProperty('detection_class', (new StringType)->nullable(true))->addProperty('isolation_class', (new StringType)->nullable(true))
                    ->addProperty('fact_di', (new NumberType)->nullable(true))->addProperty('adapted_pair', new BooleanType)
                    ->addProperty('ld_max', (new ArrayType)->setItems(new NumberType)->nullable(true))->addProperty('factmit', (new NumberType)->nullable(true))
                    ->addProperty('effective_costfactor', new NumberType)->addProperty('effective_outagemult', new NumberType)
                    ->addProperty('shift_density', (new NumberType)->nullable(true))->addProperty('total_density', (new NumberType)->nullable(true));
                $derived->setRequired(array_keys($derived->properties));
                $type->addProperty('derived', $derived);
            }
            if (class_basename($name) === 'UpdateInstallationCostRequest') {
                $type->addProperty('values', $values->clone())->setRequired(['version']);
                $sources = new ObjectType;
                foreach (array_keys(config('installation-costs.fields')) as $field) {
                    $sources->addProperty($field, $sourceInput->clone());
                }
                $type->addProperty('sources', $sources);
            }
        }
    }
}
