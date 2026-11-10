<?php

namespace App\Modules\Installations\Infrastructure;

use App\Modules\Installations\Models\InstallationMaintenanceRecord;
use App\Modules\Installations\Models\InstallationMriParameters;
use App\Modules\Installations\Models\InstallationRepairParameters;
use App\Modules\Installations\Models\InstallationStopParameters;
use Illuminate\Support\Facades\DB;
use LogicException;

final class InstallationMaintenanceRepository
{
    public function save(InstallationMaintenanceRecord $record, array $values): void
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Maintenance parameters require an aggregate transaction.');
        }
        $schema = json_decode(file_get_contents(resource_path('schemas/installation-maintenance-v1.json')), true, flags: JSON_THROW_ON_ERROR)['kinds'];
        $fields = $schema[$record->kind]['fields'] ?? throw new LogicException('Unknown maintenance kind.');
        if (array_diff(array_keys($values), array_keys($fields))) {
            throw new LogicException('Undeclared maintenance parameter.');
        }
        $record->forceFill(['reason' => $values['reason'] ?? null])->save();
        unset($fields['reason']);
        $parameters = array_replace(array_fill_keys(array_keys($fields), null), array_intersect_key($values, $fields));
        $model = match ($record->kind) {
            'mri' => InstallationMriParameters::class,'repair' => InstallationRepairParameters::class,'stop' => InstallationStopParameters::class
        };
        $model::updateOrCreate(['record_id' => $record->id], $parameters);
        $db->table('installation_maintenance_presence')->where('record_id', $record->id)->delete();
        if ($values !== []) {
            $db->table('installation_maintenance_presence')->insert(array_map(fn ($key) => ['record_id' => $record->id, 'kind' => $record->kind, 'field_key' => $key], array_keys($values)));
        }
        $record->unsetRelation('dataProjection');
    }

    /** Bind an explicitly accepted verification to the complete current input, not just its date. */
    public function bindVerification(InstallationMaintenanceRecord $record): void
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Verification requires an aggregate transaction.');
        }
        $signature = $db->selectOne('SELECT installation_maintenance_value_signature(?) AS signature', [$record->id])->signature;
        $db->table('installation_maintenance_records')->where('id', $record->id)->update(['verification_signature' => $signature]);
        $record->setAttribute('verification_signature', $signature)->syncOriginalAttribute('verification_signature');
    }
}
