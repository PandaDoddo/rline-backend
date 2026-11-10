<?php

namespace App\Modules\Rbi\Application;

use App\Modules\Installations\Application\InstallationMaintenanceSummary;
use App\Modules\Installations\Models\Installation;
use App\Modules\Rbi\Models\InstallationRiskTargets;
use App\Modules\Shared\Infrastructure\TenantWrites;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InstallationRiskCriteria
{
    public function schema(): array
    {
        static $schema;

        return $schema ??= json_decode(file_get_contents(resource_path('rbi/risk-targets-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function read(Installation $installation): array
    {
        $row = InstallationRiskTargets::with('overrides')->find($installation->id);
        $keys = $row?->overrides->pluck('field_key')->all() ?? [];
        $mri = app(InstallationMaintenanceSummary::class)->currentMri($installation)?->interval_months;
        $values = [];
        $effective = [];
        $origins = [];
        foreach ($this->schema()['fields'] as $field) {
            $key = $field['key'];
            if (in_array($key, $keys, true)) {
                $values[$key] = $row->getAttribute($key);
                $effective[$key] = $values[$key];
                $origins[$key] = 'installation';
            } elseif ($key === 'planScc' && $mri !== null) {
                $effective[$key] = $mri / 12;
                $origins[$key] = 'mri';
            } else {
                $effective[$key] = $field['default'];
                $origins[$key] = 'policy';
            }
        }

        return ['installation_id' => $installation->id, 'version' => $installation->version, 'schema_version' => $this->schema()['version'], 'policy' => $this->schema()['policy'], 'overrides' => $values, 'effective' => $effective, 'origins' => $origins, 'current_mri_months' => $mri, 'as_of' => now()->toISOString()];
    }

    public function update(string $id, array $data, int $actor): array
    {
        return app(TenantWrites::class)->transaction(function () use ($id, $data, $actor) {
            $installation = Installation::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($installation->status === 'active', 422, 'Сначала восстановите установку из архива.');
            $writes = app(TenantWrites::class);
            $writes->version($installation, $data['version']);
            abort_unless($data['schema_version'] === $this->schema()['version'], 409, 'Версия критериев риска изменилась. Обновите данные.');
            $values = $data['values'] ?? [];
            $reset = $data['reset_fields'] ?? [];
            if (array_intersect(array_keys($values), $reset)) {
                throw ValidationException::withMessages(['reset_fields' => 'Нельзя одновременно задать и сбросить один критерий.']);
            }
            $before = $this->read($installation);
            $db = DB::connection('tenant');
            $row = InstallationRiskTargets::find($id) ?? (new InstallationRiskTargets)->forceFill(['installation_id' => $id]);
            foreach ($reset as $key) {
                $row->setAttribute($key, null);
            }
            $row->forceFill($values)->save();
            $db->table('installation_risk_target_overrides')->where('installation_id', $id)->whereIn('field_key', $reset)->delete();
            foreach (array_keys($values) as $key) {
                $db->table('installation_risk_target_overrides')->updateOrInsert(['installation_id' => $id, 'field_key' => $key], []);
            }
            $after = $this->read($installation);
            if ($after['effective']['planRec'] > $after['effective']['planMax']) {
                throw ValidationException::withMessages(['values.planRec' => 'Рекомендуемый интервал не может превышать максимальный интервал планирования.']);
            }
            $installation->forceFill(['version' => $installation->version + 1, 'updated_by' => $actor])->save();
            $after['version'] = $installation->version;
            $writes->event('installation_risk_criteria', $installation, 'updated', $actor, $before, $after);

            return $after;
        });
    }
}
