<?php

namespace App\Modules\Equipment\Domain;

use InvalidArgumentException;

/** Stable CML coordinates use the physical owner's code and retain gaps in source numbering. */
final class CmlCode
{
    public function allocate(?string $requested, string $componentCode, int $next): array
    {
        $prefix = 'CML-'.str_replace(['K-', '.S'], ['', '.'], $componentCode).'.';
        $number = $next;
        if ($requested !== null) {
            if (! preg_match('/^'.preg_quote($prefix, '/').'([1-9][0-9]*)$/D', $requested, $match)) {
                throw new InvalidArgumentException('Код CML должен содержать координату своего физического элемента и номер без ведущих нулей.');
            }
            $number = (int) $match[1];
        }
        $code = $prefix.$number;
        if ($number < 1 || $number > 999999 || strlen($code) > 60) {
            throw new InvalidArgumentException('Номер CML должен быть от 1 до 999999, длина кода — до 60 символов.');
        }

        return ['code' => $code, 'next' => max($next, $number + 1)];
    }
}
