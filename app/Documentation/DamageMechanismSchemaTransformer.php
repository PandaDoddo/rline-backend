<?php

namespace App\Documentation;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class DamageMechanismSchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $groups = (new ArrayType)->setItems((new StringType)->enum(array_keys(config('damage-mechanisms.groups'))));
        $values = new ObjectType;
        $input = new ObjectType;
        $verification = new ObjectType;
        $sourceInput = new ObjectType;
        $source = (new ObjectType)->addProperty('citation', (new StringType)->nullable(true))->addProperty('conditions', (new StringType)->nullable(true));
        foreach (config('damage-mechanisms.fields') as $key => $field) {
            $property = match ($field['type']) {
                'groups' => $groups->clone(),
                'relations' => (new ArrayType)->setItems((new StringType)->format('uuid')),
                'corrosive-components' => (new ArrayType)->setItems((new ObjectType)->addProperty('name', new StringType)->addProperty('formula', (new StringType)->nullable(true))->addProperty('threshold', (new StringType)->nullable(true))->setRequired(['name', 'formula', 'threshold'])),
                'integer' => (new IntegerType)->nullable(true),
                default => (new StringType)->nullable($key !== 'name'),
            };
            if (isset($field['options'])) {
                $property->enum($field['options']);
            }
            $property->setDescription($field['label']);
            $values->addProperty($key, $property);
            if (! ($field['readonly'] ?? false)) {
                $input->addProperty($key, $property->clone());
                $verification->addProperty($key, new BooleanType);
                $sourceInput->addProperty($key, $source->clone());
            }
        }
        $check = (new ObjectType)->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['by', 'at']);
        $checks = new ObjectType;
        $checks->additionalProperties = $check;
        $source->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['citation', 'conditions', 'by', 'at']);
        $sources = new ObjectType;
        $sources->additionalProperties = $source;
        $summary = (new ObjectType)->addProperty('id', (new StringType)->format('uuid'))->addProperty('legacy_code', new StringType)->addProperty('name', new StringType)->addProperty('material_groups', $groups->clone())->addProperty('status', (new StringType)->enum(['active', 'archived']))->addProperty('version', new IntegerType);
        foreach (['english_name', 'paragraph', 'designation', 'kind', 'df_mapping'] as $key) {
            $summary->addProperty($key, (new StringType)->nullable(true));
        }
        $summary->setRequired(array_keys($summary->properties));
        foreach ($document->components->schemas as $name => $schema) {
            if (! $schema->type instanceof ObjectType) {
                continue;
            }
            $class = class_basename($name);
            $type = $schema->type;
            if ($class === 'UpdateDamageMechanismRequest') {
                $type->addProperty('values', $input)->addProperty('verification', $verification)->addProperty('sources', $sourceInput)->setRequired(['version']);
                foreach (['origin', 'base_values', 'base_version', 'checks'] as $key) {
                    unset($type->properties[$key]);
                }
            }
            if ($class === 'DamageMechanismSummaryResource') {
                foreach ($summary->properties as $key => $property) {
                    $type->addProperty($key, $property->clone());
                } $type->setRequired($summary->required);
            }
            if ($class === 'DamageMechanismResource') {
                $type->addProperty('origin', (new StringType)->enum(['base']))->addProperty('values', $values->clone()->setRequired(['name']))->addProperty('base_values', $values->clone())->addProperty('overrides', $values->clone())->addProperty('checks', $checks)->addProperty('sources', $sources)->addProperty('related', (new ArrayType)->setItems($summary->clone()))->addProperty('status', (new StringType)->enum(['active', 'archived']));
            }
            if ($class === 'DamageMechanismEventResource') {
                $change = (new ObjectType)->addProperty('key', new StringType)->addProperty('label', new StringType)->addProperty('from', (new StringType)->nullable(true))->addProperty('to', (new StringType)->nullable(true))->setRequired(['key', 'label', 'from', 'to']);
                $type->addProperty('changes', (new ArrayType)->setItems($change));
            }
        }
    }
}
