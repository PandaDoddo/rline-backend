<?php

namespace App\Modules\Shared\Domain;

/** Stable object keys, meaningful list order, and no scalar type coercion. */
final class ContentHash
{
    public static function of(array $value): string
    {
        return hash('sha256', json_encode(self::ordered($value), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private static function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            $item = self::ordered($item);
        }
        unset($item);

        return $value;
    }
}
