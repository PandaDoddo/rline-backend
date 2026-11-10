<?php

namespace App\Documentation;

use App\Modules\Rbi\Application\InstallationRiskCriteria;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class RiskCriteriaSchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $fields = app(InstallationRiskCriteria::class)->schema()['fields'];
        $values = new ObjectType;
        $origins = new ObjectType;
        foreach ($fields as $field) {
            $values->addProperty($field['key'], (new NumberType)->nullable($field['nullable']));
            $origins->addProperty($field['key'], (new StringType)->enum(['installation', 'mri', 'policy']));
        }
        foreach ($document->components->schemas as $name => $schema) {
            if (! $schema->type instanceof ObjectType) {
                continue;
            }
            if (class_basename($name) === 'RiskCriteriaResource') {
                $effective = $values->clone();
                $effective->setRequired(array_column($fields, 'key'));
                $origins->setRequired(array_column($fields, 'key'));
                $schema->type->addProperty('overrides', $values->clone())->addProperty('effective', $effective)->addProperty('origins', $origins->clone())
                    ->addProperty('current_mri_months', (new IntegerType)->nullable(true))->addProperty('data_origin', ImportOriginSchema::make()->nullable(true));
                $schema->type->setRequired(array_keys($schema->type->properties));
            }
            if (class_basename($name) === 'UpdateRiskCriteriaRequest') {
                $schema->type->addProperty('values', $values->clone())->addProperty('reset_fields', (new ArrayType)->setItems((new StringType)->enum(array_column($fields, 'key'))))->addProperty('schema_version', new StringType)->setRequired(['version', 'schema_version']);
            }
        }
    }
}
