<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class SteelGradeBaseline
{
    public function seed(): void
    {
        $baseline = json_decode(file_get_contents(resource_path('catalogs/steel-grades.json')), true, flags: JSON_THROW_ON_ERROR);
        $rows = [];
        foreach ($baseline['entries'] as $entry) {
            $values = [];
            foreach ($entry['values'] as $key => $value) {
                if (array_key_exists($key, config('steel-grades.fields'))) {
                    $values[$key] = SteelGradeValues::normalize($key, $value);
                }
            }
            $rows[] = ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'rline:steel-grade:'.$entry['code'])->toString(), 'legacy_code' => $entry['code'], 'label' => $values['name'], 'origin' => 'base', 'base_version' => $baseline['version'], 'base_values' => json_encode($values, JSON_THROW_ON_ERROR), 'base_raw' => json_encode($entry['values'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::connection('tenant')->table('steel_grades')->insertOrIgnore($rows);
    }
}
