<?php

namespace App\Domain;

use App\Models\Installation;
use App\Models\InstallationMaintenanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class InstallationMaintenance
{
    public function __construct(private TenantWrites $writes) {}

    public function update(string $id, array $data, int $actor): Installation
    {
        return $this->writes->transaction(function () use ($id, $data, $actor) {
            $installation = Installation::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->writes->version($installation, $data['version']);
            $upserts = $data['upserts'] ?? [];
            $deletes = $data['delete_ids'] ?? [];
            $ids = [...array_column($upserts, 'id'), ...$deletes];
            if (count($ids) !== count(array_unique($ids))) {
                $this->invalid('upserts', 'Одна запись не может изменяться и удаляться одновременно.');
            }
            $existing = InstallationMaintenanceRecord::withTrashed()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            foreach ($existing as $record) {
                if ($record->installation_id !== $id || $record->trashed()) {
                    abort(404, 'Запись недоступна в этой установке.');
                }
            }
            $before = $this->snapshot($existing, $installation->no_stops);
            foreach ($deletes as $recordId) {
                $record = $existing->get($recordId);
                if (! $record) {
                    abort(404, 'Удаляемая запись не найдена.');
                }
                if ($record->check) {
                    $this->invalid('delete_ids', 'Проверенную запись нельзя удалить: сначала снимите проверку и сохраните.');
                }
                $record->delete();
            }
            foreach ($upserts as $index => $entry) {
                $record = $existing->get($entry['id']);
                $kind = $entry['kind'];
                if ($record && $record->kind !== $kind) {
                    $this->invalid('upserts.'.$index.'.kind', 'Вид записи неизменяем.');
                }
                $allowed = config('installation-maintenance.fields.'.$kind);
                foreach (array_keys($entry['values'] ?? []) as $key) {
                    if (! array_key_exists($key, $allowed)) {
                        $this->invalid('upserts.'.$index.'.values.'.$key, 'Поле не относится к этому виду записи.');
                    }
                }
                $old = $record?->values ?? [];
                $values = array_replace($old, $entry['values'] ?? []);
                foreach (['starts_at', 'ends_at'] as $key) {
                    if (isset($values[$key])) {
                        $values[$key] = CarbonImmutable::parse($values[$key])->utc()->toISOString();
                    }
                }
                if (isset($values['interval_months'])) {
                    $values['interval_months'] = (int) $values['interval_months'];
                }
                $changed = ! CatalogValues::same($old, $values);
                if ($record?->check && $changed && ($entry['verified'] ?? true) && ! ($entry['unlock'] ?? false)) {
                    $this->invalid('upserts.'.$index, 'Проверенная запись защищена: сначала снимите проверку.');
                }
                $this->validateValues($kind, $values, 'upserts.'.$index.'.values');
                $check = $record?->check;
                if (($entry['verified'] ?? null) === false || $changed || ($entry['unlock'] ?? false)) {
                    $check = null;
                }
                if ($entry['verified'] ?? false) {
                    foreach ($this->requiredFields($kind) as $key) {
                        if (! isset($values[$key])) {
                            $this->invalid('upserts.'.$index.'.verified', 'Для проверки заполните даты, интервал или вид ремонта.');
                        }
                    }
                    $check ??= ['by' => $actor, 'at' => now()->toIso8601String()];
                }
                $record ??= (new InstallationMaintenanceRecord)->forceFill(['id' => $entry['id'], 'installation_id' => $id, 'kind' => $kind, 'created_by' => $actor]);
                $record->forceFill(['values' => $values, 'check' => $check, 'updated_by' => $actor])->save();
            }
            $noStops = array_key_exists('no_stops', $data) ? $data['no_stops'] : $installation->no_stops;
            if (InstallationMaintenanceRecord::where('installation_id', $id)->where('kind', 'mri')->whereRaw("installation_maintenance_records.values->>'effective_date' IS NOT NULL")->groupByRaw("installation_maintenance_records.values->>'effective_date'")->havingRaw('count(*) > 1')->selectRaw('1')->exists()) {
                $this->invalid('upserts', 'На одну дату допускается одна запись МРИ. Исправьте существующую запись.');
            }
            if ($noStops && InstallationMaintenanceRecord::where('installation_id', $id)->where('kind', 'stop')->exists()) {
                $this->invalid('no_stops', 'Отметка «остановов не было» несовместима с записями остановов.');
            }
            $installation->forceFill(['no_stops' => $noStops, 'version' => $installation->version + 1, 'updated_by' => $actor])->save();
            $after = $this->snapshot(InstallationMaintenanceRecord::whereIn('id', $ids)->get()->keyBy('id'), $noStops);
            $this->writes->event('installation_maintenance', $installation, 'updated', $actor, $before, $after);

            return $installation;
        });
    }

    public function requiredFields(string $kind): array
    {
        return match ($kind) {
            'mri' => ['effective_date', 'interval_months'], 'repair' => ['starts_on', 'ends_on', 'repair_kind'], 'stop' => ['starts_at', 'ends_at']
        };
    }

    private function validateValues(string $kind, array $values, string $path): void
    {
        foreach ([['starts_on', 'ends_on'], ['starts_at', 'ends_at']] as [$start, $end]) {
            if (isset($values[$start], $values[$end]) && CarbonImmutable::parse($values[$end])->lt(CarbonImmutable::parse($values[$start]))) {
                $this->invalid($path.'.'.$end, 'Окончание не может быть раньше начала.');
            }
        }
    }

    private function snapshot($records, ?bool $noStops): array
    {
        $entries = [];
        foreach ($records as $record) {
            $entries[$record->id] = ['kind' => $record->kind, 'values' => $record->values, 'check' => $record->check];
        }

        return ['overrides' => ['entries' => $entries, 'no_stops' => $noStops]];
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
