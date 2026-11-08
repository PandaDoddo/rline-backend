<?php

namespace App\Documentation;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;

class FacilitySchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $schemas = [];
        foreach ($document->components->schemas as $name => $schema) {
            $schemas[class_basename($name)] = $schema;
        }
        foreach (['InstallationRequest' => 'version', 'FacilityNodeRequest' => 'version', 'UpdateFacilityNodeRequest' => 'kind'] as $schema => $field) {
            $type = $schemas[$schema]->type ?? null;
            if ($type instanceof ObjectType) {
                unset($type->properties[$field]);
                $type->setRequired(array_values(array_diff($type->required, [$field])));
            }
        }
        $passport = $schemas['InstallationRequest']->type->properties['passport'] ?? null;
        $resource = $schemas['InstallationResource']->type ?? null;
        if ($passport && $resource instanceof ObjectType) {
            $resource->addProperty('passport', $passport);
        }
    }
}
