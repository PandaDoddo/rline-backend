<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class SubstanceBaseline
{
    public function seed(): void
    {
        $baseline = json_decode(file_get_contents(resource_path('catalogs/substances.json')), true, flags: JSON_THROW_ON_ERROR);
        $rows = [];
        foreach ($baseline['entries'] as $entry) {
            $values = [];
            foreach ($entry['values'] as $key => $value) {
                if (array_key_exists($key, config('substances.fields'))) {
                    $values[$key] = SubstanceValues::normalize($key, $value);
                }
            }
            $rows[] = ['id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'rline:substance:'.$entry['code']), 'legacy_code' => $entry['code'], 'label' => $values['name'],
                'origin' => 'base', 'base_version' => $baseline['version'], 'base_values' => json_encode($values, JSON_THROW_ON_ERROR), 'base_raw' => json_encode($entry['values'], JSON_THROW_ON_ERROR),
                'base_flags' => json_encode($baseline['flags'][$entry['code']] ?? new \stdClass, JSON_THROW_ON_ERROR), 'overrides' => '{}', 'sources' => '{}', 'checks' => '{}', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        // Reapplying this initial baseline must never replace a tenant's edits or archive decisions.
        DB::connection('tenant')->table('substances')->insertOrIgnore($rows);
    }
}
