<?php

namespace App\Modules\Shared\Domain;

/** Preserve unknown/zero/false and textual identifiers; numeric int/float representations may match. */
class ValueEquality
{
    public static function same(mixed $before, mixed $after): bool
    {
        if ($before === $after) {
            return true;
        }
        if (is_int($before) && is_float($after) || is_float($before) && is_int($after)) {
            $integer = is_int($before) ? $before : $after;
            $float = is_float($before) ? $before : $after;

            return is_finite($float) && $float >= PHP_INT_MIN && $float < PHP_INT_MAX && floor($float) === $float && (int) $float === $integer;
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
