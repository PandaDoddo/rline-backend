<?php

namespace App\Modules\Equipment\Infrastructure;

use App\Modules\Equipment\Domain\PassportSchema;
use App\Modules\Equipment\Models\Equipment;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EquipmentPassportRepository
{
    /** Called within the equipment aggregate transaction, after its version is locked. */
    public function save(Equipment $equipment, array $passport, array $qualifiers, array $checks): void
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Passport persistence requires an aggregate transaction.');
        }
        if (array_diff(array_keys($passport), array_keys(PassportSchema::fields()))) {
            throw new LogicException('Passport field is missing from the versioned relational schema.');
        }
        foreach (PassportSchema::tables() as $table => $fields) {
            if ($table === 'equipment') {
                continue;
            }
            $values = [];
            foreach ($fields as $key => $field) {
                $values[$key] = $passport[$key] ?? null;
            }
            $db->table($table)->updateOrInsert(['equipment_id' => $equipment->id], $values);
        }
        $annotations = [];
        foreach (array_unique([...array_keys($qualifiers), ...array_keys($checks)]) as $key) {
            $annotation = $qualifiers[$key] ?? [];
            $check = $checks[$key] ?? [];
            if (! $annotation && ! $check) {
                continue;
            }
            $annotations[] = ['equipment_id' => $equipment->id, 'field_key' => $key, 'state' => $annotation['state'] ?? null, 'precision' => $annotation['precision'] ?? null,
                'verified_by' => $check['by'] ?? null, 'verified_at' => $check['at'] ?? null];
        }
        $db->table('equipment_passport_annotations')->where('equipment_id', $equipment->id)->delete();
        if ($annotations) {
            $db->table('equipment_passport_annotations')->insert($annotations);
        }
        $equipment->unsetRelation('passportProjection');
    }
}
