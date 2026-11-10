<?php

namespace App\Modules\Catalogs\Domain;

/** Source expressions are annotations, never accepted scalar values for calculations. */
final class SubstanceReferenceValue
{
    public static function accepts(string $key, mixed $value): bool
    {
        if ($key === 'idlh' && $value === 'не определено') {
            return true;
        }
        if (! is_string($value) || strlen($value) > 100 || ! preg_match('/^(mw|nbp|ait|dens|idlh|pdk)_(icsc|cameo|kor|nfpa)$/', $key)) {
            return false;
        }
        if ($value === 'varies') {
            return true;
        }
        $number = '(-?\d+(?:\.\d+)?)';
        $field = config('substances.fields.'.$key);
        $valid = fn ($n) => is_finite((float) $n) && $n >= $field['min'] && $n <= $field['max'];
        if (preg_match('/^'.$number.'(?:\.\.|-)'.$number.'$/D', $value, $matches)) {
            return $valid($matches[1]) && $valid($matches[2]) && $matches[1] <= $matches[2];
        }
        if (preg_match('/^[<>]=?'.$number.'$/D', $value, $matches)) {
            return $valid($matches[1]);
        }

        return false;
    }
}
