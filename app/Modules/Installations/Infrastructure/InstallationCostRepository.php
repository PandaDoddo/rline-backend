<?php

namespace App\Modules\Installations\Infrastructure;

use App\Modules\Installations\Models\Installation;
use App\Modules\Installations\Models\InstallationCostParameters;
use Illuminate\Support\Facades\DB;
use LogicException;

final class InstallationCostRepository
{
    public function save(Installation $installation, array $values, array $sources, array $checks): void
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Installation costs require an aggregate transaction.');
        }
        $fields = json_decode(file_get_contents(resource_path('schemas/installation-cost-properties-v1.json')), true, flags: JSON_THROW_ON_ERROR)['fields'];
        $keys = array_unique([...array_keys($values), ...array_keys($sources), ...array_keys($checks)]);
        if (array_diff($keys, array_keys($fields))) {
            throw new LogicException('Undeclared installation cost field.');
        }
        InstallationCostParameters::updateOrCreate(['installation_id' => $installation->id], array_replace(array_fill_keys(array_keys($fields), null), $values));
        $annotations = [];
        foreach ($keys as $key) {
            $source = $sources[$key] ?? null;
            $check = $checks[$key] ?? null;
            $annotations[] = ['installation_id' => $installation->id, 'field_key' => $key, 'value_present' => array_key_exists($key, $values),
                'source_present' => $source !== null, 'citation' => $source['citation'] ?? null, 'conditions' => $source['conditions'] ?? null,
                'source_by' => $source['by'] ?? null, 'source_at' => $source['at'] ?? null, 'verified_by' => $check['by'] ?? null, 'verified_at' => $check['at'] ?? null];
        }
        $db->table('installation_cost_annotations')->where('installation_id', $installation->id)->delete();
        if ($annotations !== []) {
            $db->table('installation_cost_annotations')->insert($annotations);
        }
        $installation->unsetRelation('costProjection');
    }
}
