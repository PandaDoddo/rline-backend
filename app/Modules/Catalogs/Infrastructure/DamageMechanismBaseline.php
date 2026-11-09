<?php

namespace App\Modules\Catalogs\Infrastructure;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class DamageMechanismBaseline
{
    public static function id(string $code): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'rline:damage-mechanism:'.$code)->toString();
    }

    public function seed(): void
    {
        $baseline = json_decode(file_get_contents(resource_path('catalogs/damage-mechanisms.json')), true, flags: JSON_THROW_ON_ERROR);
        $codes = array_column($baseline['entries'], 'code');
        $rows = [];
        foreach ($baseline['entries'] as $entry) {
            $values = $entry['values'];
            foreach ($values['related_codes'] as $related) {
                if (! in_array($related, $codes, true)) {
                    throw new \RuntimeException('Unknown baseline mechanism relation: '.$related);
                }
            }
            $values['related_ids'] = array_map(self::id(...), $values['related_codes']);
            unset($values['related_codes']);
            foreach ($values as $key => $value) {
                if (is_string($value)) {
                    $values[$key] = trim($value) === '' || $value === '—' ? null : trim($value);
                }
            }
            $rows[] = ['id' => self::id($entry['code']), 'legacy_code' => $entry['code'], 'label' => $values['name'], 'origin' => 'base', 'base_version' => $baseline['version'], 'base_values' => json_encode($values, JSON_THROW_ON_ERROR), 'base_raw' => json_encode($entry['raw'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::connection('tenant')->table('damage_mechanisms')->insertOrIgnore($rows);
    }
}
