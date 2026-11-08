<?php

namespace App\Documentation;

use App\Auth\AuthPluginManager;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class FortifySchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        foreach ($document->components->schemas as $name => $schema) {
            if (! $schema instanceof Schema || ! $schema->type instanceof ObjectType) {
                continue;
            }
            if (class_basename($name) === 'LoginRequest') {
                $schema->type->addProperty('provider', (new StringType)->enum(app(AuthPluginManager::class)->credentialProviderIds())->default('local')->setDescription('Должен быть включён в выбранном тенанте.'));
                unset($schema->type->properties['remember']);
            }
            foreach ($schema->type->properties as $property => $type) {
                if ($type instanceof StringType && str_contains($property, 'password')) {
                    $type->format('password');
                }
            }
        }
    }
}
