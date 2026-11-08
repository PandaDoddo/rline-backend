<?php

namespace App\Domain;

class SubstanceValues
{
    public static function normalize(string $key, mixed $value): mixed
    {
        $field = config('substances.fields')[$key] ?? null;
        if (! $field) {
            return $value;
        }
        if (is_string($value)) {
            $value = trim($value);
            if (in_array($value, ['', '—', '---'], true) || $value === '-' && $key !== 'nfpaS') {
                return null;
            }
            if (in_array($field['type'], ['number', 'integer'], true)) {
                $number = str_replace([' ', ','], ['', '.'], $value);
                if (is_numeric($number)) {
                    return $number + 0;
                }
            }
        }
        if ($field['type'] === 'choice' && is_int($value)) {
            return (string) $value;
        }
        if ($field['type'] === 'boolean' && in_array($value, [0, 1, '0', '1'], true)) {
            return (bool) (int) $value;
        }

        return $value;
    }
}
