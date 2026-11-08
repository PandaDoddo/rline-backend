<?php

namespace App\Domain;

class CatalogValues
{
    public static function same(mixed $before, mixed $after): bool
    {
        if ($before === $after) {
            return true;
        }
        if ((is_int($before) || is_float($before)) && (is_int($after) || is_float($after))) {
            return (float) $before === (float) $after;
        }
        if (! is_array($before) || ! is_array($after) || count($before) !== count($after) || array_is_list($before) !== array_is_list($after)) {
            return false;
        }
        foreach ($before as $key => $value) {
            if (! array_key_exists($key, $after) || ! self::same($value, $after[$key])) {
                return false;
            }
        }

        return true;
    }
}
