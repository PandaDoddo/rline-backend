<?php

namespace App\Modules\Equipment\Domain;

use InvalidArgumentException;

/** Stable coordinates; legacy air-cooler section/collector roots use their own S/C namespaces. */
final class ComponentCode
{
    /** @return array{code:string,next:int} */
    public function allocate(?string $requested, ?string $parent, int $next): array
    {
        $prefix = '';
        if ($requested === null) {
            $number = $next;
            $advances = true;
        } else {
            $pattern = $parent !== null ? '/^'.preg_quote($parent, '/').'\.S([0-9]+)$/D' : '/^K-([SC]?)([0-9]+)$/D';
            if (! preg_match($pattern, $requested, $match)) {
                throw new InvalidArgumentException('Укажите K-NN, исходный K-SN/K-CN либо код элемента своего родителя с суффиксом .SN.');
            }
            $prefix = $parent === null ? $match[1] : '';
            $number = (int) $match[$parent === null ? 2 : 1];
            $advances = $parent !== null || $prefix === '';
        }
        if ($number < 1 || $number > 999999) {
            throw new InvalidArgumentException('Номер компонента должен быть от 1 до 999999.');
        }
        $code = $parent !== null ? $parent.'.S'.$number : 'K-'.($prefix !== '' ? $prefix.$number : str_pad((string) $number, 2, '0', STR_PAD_LEFT));
        if ($requested !== null && $requested !== $code) {
            throw new InvalidArgumentException('Код должен иметь канонический формат без лишних ведущих нулей.');
        }
        if (strlen($code) > 40) {
            throw new InvalidArgumentException('Длина полного кода компонента не должна превышать 40 символов.');
        }

        return ['code' => $code, 'next' => $advances ? max($next, $number + 1) : $next];
    }
}
