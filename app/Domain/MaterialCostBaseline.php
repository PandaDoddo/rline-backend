<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class MaterialCostBaseline
{
    public function seed(): void
    {
        $base = json_decode(file_get_contents(resource_path('catalogs/material-costs.json')), true, flags: JSON_THROW_ON_ERROR);
        $rows = [];
        foreach ($base['entries'] as $i => $entry) {
            $rows[] = ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'rline:material-cost:'.$entry['english'])->toString(), 'legacy_code' => 'm'.$i,
                'label' => $entry['name'], 'origin' => 'base', 'base_version' => $base['version'],
                'base_values' => json_encode(['name' => $entry['name'], 'factor' => $entry['factor']], JSON_THROW_ON_ERROR),
                'base_raw' => json_encode($entry, JSON_THROW_ON_ERROR), 'material_group' => $entry['group'], 'english_name' => $entry['english'],
                'adapted' => $entry['adapted'], 'position' => $i, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::connection('tenant')->table('material_costs')->insertOrIgnore($rows);
    }
}
