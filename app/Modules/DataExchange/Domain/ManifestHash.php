<?php

namespace App\Modules\DataExchange\Domain;

/** Source content hashing is independent of object key order; list order remains meaningful. */
final class ManifestHash
{
    public static function of(array $manifest): string
    {
        return hash('sha256', json_encode(self::ordered($manifest), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
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
