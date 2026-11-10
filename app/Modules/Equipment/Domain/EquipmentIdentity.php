<?php

namespace App\Modules\Equipment\Domain;

final class EquipmentIdentity
{
    // Passport-owned identifiers; the protected asset position is not a valve's own unique tag.
    public const POSITION_SQL = 'COALESCE(NULLIF(btrim("equipment"."tag"), \'\'), NULLIF(btrim("equipment"."line_numbers"), \'\'), NULLIF(btrim("equipment"."protected_equipment_position"), \'\'))';

    public static function position(array $passport): ?string
    {
        foreach (['tag', 'line_numbers', 'protected_equipment_position'] as $key) {
            $value = trim((string) ($passport[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
