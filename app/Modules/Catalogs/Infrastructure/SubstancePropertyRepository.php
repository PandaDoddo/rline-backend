<?php

namespace App\Modules\Catalogs\Infrastructure;

use App\Modules\Catalogs\Domain\SubstancePropertySchema;
use App\Modules\Catalogs\Domain\SubstanceReferenceValue;
use App\Modules\Catalogs\Models\Substance;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SubstancePropertyRepository
{
    public function save(Substance $substance, array $values, array $sources, array $checks): void
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Substance properties require an aggregate transaction.');
        }
        $fields = SubstancePropertySchema::fields();
        $keys = array_unique([...array_keys($values), ...array_keys($sources), ...array_keys($checks)]);
        if (array_diff($keys, array_keys($fields))) {
            throw new LogicException('Undeclared substance property.');
        }
        $properties = [];
        foreach ($fields as $key => $field) {
            if ($field['column'] !== null) {
                $value = $values[$key] ?? null;
                $properties[$field['column']] = in_array($value, $field['qualifiers'] ?? [], true) || SubstanceReferenceValue::accepts($key, $value) ? null : $value;
            } elseif (array_key_exists($key, $values)) {
                throw new LogicException('Read-only baseline property cannot be overridden.');
            }
        }
        $annotations = [];
        foreach ($keys as $key) {
            $source = $sources[$key] ?? null;
            $check = $checks[$key] ?? null;
            $value = $values[$key] ?? null;
            if (SubstanceReferenceValue::accepts($key, $value)) {
                // The existing normalized qualifier/annotation model preserves the exact source expression.
                $db->table('substance_property_qualifiers')->insertOrIgnore(['field_key' => $key, 'qualifier' => $value]);
            }
            $annotations[] = ['substance_id' => $substance->id, 'field_key' => $key, 'value_present' => array_key_exists($key, $values),
                'qualifier' => in_array($value, $fields[$key]['qualifiers'] ?? [], true) || SubstanceReferenceValue::accepts($key, $value) ? $value : null,
                'source_present' => $source !== null, 'citation' => $source['citation'] ?? null, 'conditions' => $source['conditions'] ?? null,
                'source_by' => $source['by'] ?? null, 'source_at' => $source['at'] ?? null,
                'verified_by' => $check['by'] ?? null, 'verified_at' => $check['at'] ?? null];
        }
        $db->table('substance_properties')->updateOrInsert(['substance_id' => $substance->id], $properties);
        $db->table('substance_property_annotations')->where('substance_id', $substance->id)->delete();
        if ($annotations !== []) {
            $db->table('substance_property_annotations')->insert($annotations);
        }
        $substance->unsetRelation('cardProjection');
    }
}
