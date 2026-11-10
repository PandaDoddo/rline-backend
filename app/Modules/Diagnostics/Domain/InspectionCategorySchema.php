<?php

namespace App\Modules\Diagnostics\Domain;

final class InspectionCategorySchema
{
    public const VERSION = 'inspection-categories-v1';

    /** @return array{version:string,categories:array<int,string>,groups:array<int,array{code:string,label:string,automatic:bool,cracks:bool,mechanisms:array<int,string>}>} */
    public static function metadata(): array
    {
        static $schema;

        return $schema ??= json_decode(file_get_contents(dirname(__DIR__, 4).'/resources/schemas/inspection-categories-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function groups(): array
    {
        return array_column(self::metadata()['groups'], null, 'code');
    }
}
