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

class SubstanceSchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $values = new ObjectType;
        $inputValues = new ObjectType;
        foreach (config('substances.fields') as $key => $field) {
            if (in_array($field['type'], ['number', 'integer'], true)) {
                $number = $field['type'] === 'integer' ? new IntegerType : new NumberType;
                $number->setMin($field['min'])->setMax($field['max'])->nullable(true);
                $type = (new AnyOf)->setItems([$number, (new StringType)->enum([...config('substances.missing_values'), ...($field['qualifiers'] ?? [])])]);
            } elseif ($field['type'] === 'boolean') {
                $type = (new BooleanType)->nullable(true);
            } elseif ($field['type'] === 'mix') {
                $type = (new ArrayType)->setItems((new ObjectType)->addProperty('n', new StringType)->addProperty('p', new NumberType)->setRequired(['n', 'p']))->nullable(true);
            } else {
                $type = (new StringType)->nullable($key !== 'name');
                if (isset($field['options'])) {
                    $type->enum($field['options']);
                }
            }
            $type->setDescription($field['label'].(isset($field['unit']) ? ', '.$field['unit'] : ''));
            $values->addProperty($key, $type);
            if (! ($field['readonly'] ?? false)) {
                $inputValues->addProperty($key, $type->clone());
            }
        }
        $check = (new ObjectType)->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['by', 'at']);
        $checks = new ObjectType;
        $checks->additionalProperties = $check;
        $source = (new ObjectType)->addProperty('citation', (new StringType)->nullable(true))->addProperty('conditions', (new StringType)->nullable(true))
            ->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['citation', 'conditions', 'by', 'at']);
        $sources = new ObjectType;
        $sources->additionalProperties = $source;
        $sourceInput = (new ObjectType)->addProperty('citation', (new StringType)->nullable(true))->addProperty('conditions', (new StringType)->nullable(true));
        foreach ($document->components->schemas as $name => $schema) {
            if (! $schema->type instanceof ObjectType) {
                continue;
            }
            $class = class_basename($name);
            $type = $schema->type;
            if (in_array($class, ['SubstanceRequest', 'UpdateSubstanceRequest'], true)) {
                $write = $inputValues->clone();
                if ($class === 'SubstanceRequest') {
                    $write->setRequired(['name']);
                }
                $type->addProperty('values', $write);
                $sourceFields = new ObjectType;
                foreach (array_keys(config('substances.fields')) as $key) {
                    $sourceFields->addProperty($key, $sourceInput->clone());
                }
                $type->addProperty('sources', $sourceFields);
                foreach ($class === 'SubstanceRequest' ? ['version', 'origin', 'base_values', 'base_version', 'checks', 'status'] : ['origin', 'base_values', 'base_version', 'checks'] as $key) {
                    unset($type->properties[$key]);
                    $type->setRequired(array_values(array_diff($type->required, [$key])));
                }
                if ($class === 'UpdateSubstanceRequest') {
                    $verification = new ObjectType;
                    foreach (array_keys(config('substances.fields')) as $key) {
                        $verification->addProperty($key, new BooleanType);
                    } $type->addProperty('verification', $verification);
                }
            }
            if (in_array($class, ['SubstanceResource', 'SubstanceSummaryResource'], true)) {
                $type->addProperty('origin', (new StringType)->enum(['base', 'custom']));
                $type->addProperty('status', (new StringType)->enum(['active', 'archived']));
            }
            if ($class === 'SubstanceResource') {
                $type->addProperty('legacy_code', (new StringType)->nullable(true));
                $type->addProperty('base_version', (new StringType)->nullable(true));
                $effective = $values->clone()->setRequired(['name']);
                $type->addProperty('values', $effective);
                $type->addProperty('base_values', $values->clone());
                $type->addProperty('overrides', $values->clone());
                $type->addProperty('checks', $checks);
                $type->addProperty('sources', $sources);
                $flags = new ObjectType;
                $flags->additionalProperties = new StringType;
                $type->addProperty('base_flags', (new ObjectType)->addProperty('note', new StringType)->addProperty('fields', $flags));
                $type->addProperty('classification', (new ObjectType)->addProperty('group_description', (new StringType)->nullable(true))->addProperty('warnings', (new ArrayType)->setItems(new StringType))->setRequired(['group_description', 'warnings']));
            }
            if ($class === 'SubstanceEventResource') {
                $change = (new ObjectType)->addProperty('key', new StringType)->addProperty('label', new StringType)
                    ->addProperty('from', (new StringType)->nullable(true))->addProperty('to', (new StringType)->nullable(true))->setRequired(['key', 'label', 'from', 'to']);
                $type->addProperty('changes', (new ArrayType)->setItems($change));
            }
            if ($class === 'SubstanceSummaryResource') {
                $type->addProperty('name', new StringType);
                $type->addProperty('formula', (new StringType)->nullable(true));
                $type->addProperty('group', (new StringType)->nullable(true));
            }
        }
    }
}
