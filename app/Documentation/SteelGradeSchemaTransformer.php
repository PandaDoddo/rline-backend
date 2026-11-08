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

class SteelGradeSchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $values = new ObjectType;
        $inputValues = new ObjectType;
        foreach (config('steel-grades.fields') as $key => $field) {
            if (in_array($field['type'], ['number', 'integer'], true)) {
                $number = $field['type'] === 'integer' ? new IntegerType : new NumberType;
                $number->setMin($field['min'])->setMax($field['max'])->nullable(true);
                $type = (new AnyOf)->setItems([$number, (new StringType)->enum([...config('steel-grades.missing_values'), ...($field['qualifiers'] ?? [])])]);
            } elseif ($field['type'] === 'composition') {
                $item = (new ObjectType)->addProperty('el', new StringType)->addProperty('min', (new NumberType)->nullable(true))->addProperty('max', (new NumberType)->nullable(true))->setRequired(['el']);
                $type = (new ArrayType)->setItems($item)->nullable(true);
            } elseif ($field['type'] === 'stress') {
                $column = (new ObjectType)->addProperty('id', new StringType)->addProperty('label', new StringType)->setRequired(['id', 'label']);
                $row = (new ObjectType)->addProperty('t', new NumberType)->setRequired(['t']);
                $row->additionalProperties = (new AnyOf)->setItems([(new NumberType)->nullable(true), (new StringType)->nullable(true)]);
                $type = (new ObjectType)->addProperty('columns', (new ArrayType)->setItems($column))->addProperty('rows', (new ArrayType)->setItems($row))->setRequired(['columns', 'rows'])->nullable(true);
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
            if (in_array($class, ['SteelGradeRequest', 'UpdateSteelGradeRequest'], true)) {
                $write = $inputValues->clone();
                if ($class === 'SteelGradeRequest') {
                    $write->setRequired(['name']);
                }
                $type->addProperty('values', $write);
                $sourceFields = new ObjectType;
                foreach (array_keys(config('steel-grades.fields')) as $key) {
                    $sourceFields->addProperty($key, $sourceInput->clone());
                }
                $type->addProperty('sources', $sourceFields);
                foreach ($class === 'SteelGradeRequest' ? ['version', 'origin', 'base_values', 'base_version', 'checks', 'status'] : ['origin', 'base_values', 'base_version', 'checks'] as $key) {
                    unset($type->properties[$key]);
                    $type->setRequired(array_values(array_diff($type->required, [$key])));
                }
                if ($class === 'UpdateSteelGradeRequest') {
                    $type->setRequired(['version']);
                    $verification = new ObjectType;
                    foreach (array_keys(config('steel-grades.fields')) as $key) {
                        $verification->addProperty($key, new BooleanType);
                    } $type->addProperty('verification', $verification);
                }
            }
            if (in_array($class, ['SteelGradeResource', 'SteelGradeSummaryResource'], true)) {
                $type->addProperty('origin', (new StringType)->enum(['base', 'custom']));
                $type->addProperty('status', (new StringType)->enum(['active', 'archived']));
            }
            if ($class === 'SteelGradeResource') {
                $type->addProperty('legacy_code', (new StringType)->nullable(true));
                $type->addProperty('base_version', (new StringType)->nullable(true));
                $effective = $values->clone()->setRequired(['name']);
                $type->addProperty('values', $effective);
                $type->addProperty('base_values', $values->clone());
                $type->addProperty('overrides', $values->clone());
                $type->addProperty('checks', $checks);
                $type->addProperty('sources', $sources);
                $type->addProperty('assessment', (new ObjectType)->addProperty('warnings', (new ArrayType)->setItems(new StringType))->addProperty('accepted_matcost', (new NumberType)->nullable(true))->addProperty('matcost_notice', new StringType)->addProperty('stress_policy', new StringType)->setRequired(['warnings', 'accepted_matcost', 'matcost_notice', 'stress_policy']));
            }
            if ($class === 'SteelGradeEventResource') {
                $change = (new ObjectType)->addProperty('key', new StringType)->addProperty('label', new StringType)
                    ->addProperty('from', (new StringType)->nullable(true))->addProperty('to', (new StringType)->nullable(true))->setRequired(['key', 'label', 'from', 'to']);
                $type->addProperty('changes', (new ArrayType)->setItems($change));
            }
            if ($class === 'SteelGradeSummaryResource') {
                $type->addProperty('name', new StringType);
                foreach (['analog', 'subtype', 'standard'] as $key) {
                    $type->addProperty($key, (new StringType)->nullable(true));
                }
            }
        }
    }
}
