<?php

namespace App\Modules\Diagnostics\Domain;

final class VisualInspectionSchema
{
    public const VERSION = 'visual-inspections-v1';

    public const KINDS = ['ТО-1', 'ТО-2', 'ЭПБ', 'НО'];

    public const CORROSION_FIELDS = ['corrosion_kind', 'severity', 'depth_mm', 'affected_area', 'area_unit'];

    /** @return array{version:string,fields:array<int,array{key:string,label:string,type:'boolean'|'choice'|'number'|'text',options?:array<string,string>,min?:float,max?:float,max_length?:int}>} */
    public static function metadata(): array
    {
        static $schema;

        return $schema ??= json_decode(file_get_contents(resource_path('schemas/visual-inspections-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function fields(): array
    {
        return array_column(self::metadata()['fields'], null, 'key');
    }
}
