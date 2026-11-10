<?php

namespace App\Modules\Catalogs\Domain;

/** Fixed relational schema. A new public property requires a versioned SQL migration. */
final class SubstancePropertySchema
{
    public static function fields(): array
    {
        return json_decode(file_get_contents(resource_path('schemas/substance-properties-v1.json')), true, flags: JSON_THROW_ON_ERROR)['fields'];
    }
}
