<?php

namespace App\Modules\Equipment\Domain;

final class PassportSchema
{
    public const VERSION = 'equipment-passport-v4';

    public static function fields(): array
    {
        static $fields;

        return $fields ??= json_decode(file_get_contents(resource_path('schema/equipment-passport-v4.json')), true, flags: JSON_THROW_ON_ERROR)['fields'];
    }

    public static function tables(): array
    {
        $tables = [];
        foreach (self::fields() as $key => $field) {
            $tables[$field['table']][$key] = $field;
        }

        return $tables;
    }

    public static function core(array $passport): array
    {
        $values = [];
        foreach (self::tables()['equipment'] as $key => $field) {
            $values[$key] = $passport[$key] ?? null;
        }

        return $values;
    }
}
