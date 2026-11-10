<?php

namespace App\Documentation;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NullType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/** Answer type is determined by the versioned question, not by a scalar validation rule. */
class ManagementSchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        foreach ($document->components->schemas as $name => $schema) {
            if (class_basename($name) === 'ManagementResource' && $schema->type instanceof ObjectType) {
                $issue = (new ObjectType)->addProperty('source_path', new StringType)->addProperty('kind', new StringType)
                    ->addProperty('severity', (new StringType)->enum(['warning', 'error']))->addProperty('message', new StringType)->setRequired(['source_path', 'kind', 'severity', 'message']);
                $schema->type->addProperty('data_origin', ImportOriginSchema::make()->nullable(true))->addProperty('source_issues', (new ArrayType)->setItems($issue))->addProperty('source_issues_truncated', new BooleanType);
                $schema->type->setRequired(array_keys($schema->type->properties));
            }
            if (class_basename($name) !== 'UpdateManagementRequest' || ! $schema->type instanceof ObjectType) {
                continue;
            }
            $answers = $schema->type->properties['answers'] ?? null;
            if ($answers instanceof ArrayType && $answers->items instanceof ObjectType) {
                $answers->items->addProperty('value', (new AnyOf)->setItems([new BooleanType, new NumberType, (new ArrayType)->setItems(new IntegerType), new NullType]));
            }
        }
    }
}
