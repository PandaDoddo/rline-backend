<?php

namespace App\Modules\Equipment\Domain\Screening;

final class ComponentScreeningSchema
{
    public const VERSION = 'component-screening-v1';

    /**
     * @return array{version:string, source:array{file:string,sha256:string,flow_schema_version:string}, mechanisms:list<array{id:string,label:string,english_label:string,source:string,damage_mechanisms:list<string>,component_only:bool,group:string,side_policy:string,questions:list<array{id:string,label:string,english_label:string,scope:string,rule:string,conditions:list<array{field:string,operator:string,threshold?:list<float>,near?:list<float>}>}>}>}
     */
    public static function metadata(): array
    {
        static $schema;

        return $schema ??= json_decode(file_get_contents(dirname(__DIR__, 5).'/resources/catalogs/component-screening-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
