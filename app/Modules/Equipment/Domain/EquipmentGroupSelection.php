<?php

namespace App\Modules\Equipment\Domain;

final class EquipmentGroupSelection
{
    public const STATIC = ['ves', 'pipe', 'tank', 'boiler', 'ppk', 'furnace'];

    public const DYNAMIC = ['pump', 'compr', 'blower', 'turb', 'det', 'fan'];

    public static function rule(): string
    {
        $codes = implode('|', [...self::STATIC, ...self::DYNAMIC, 'lift']);

        return 'regex:/^(?:none|(?:'.$codes.')(?:,(?:'.$codes.'))*)$/D';
    }
}
