<?php

namespace App\Modules\DataExchange\Application;

use App\Models\User;
use App\Modules\DataExchange\Domain\ManifestHash;
use App\Modules\DataExchange\Models\DatasetImportBatch;
use App\Modules\Equipment\Application\EquipmentCommands;
use App\Modules\Equipment\Application\EquipmentInputValidation;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Installations\Application\FacilityCommands;
use App\Modules\Installations\Application\FacilityInputValidation;
use App\Modules\Shared\Infrastructure\TenantWrites;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Initial dataset import. Replay never overwrites subsequent user changes. */
final class DatasetImport
{
    public function __construct(private readonly DatasetManifest $manifests, private readonly FacilityInputValidation $facilityInputs, private readonly FacilityCommands $facilities,
        private readonly EquipmentInputValidation $equipmentInputs, private readonly EquipmentCommands $equipment, private readonly TenantWrites $writes, private readonly TenantContext $context) {}

    public function preview(array $manifest, int $actor, bool $allowSourceIssues = false, ?callable $progress = null): array
    {
        try {
            return $this->run($manifest, $actor, true, $allowSourceIssues, $progress);
        } catch (PreviewFinished $preview) {
            return $preview->report;
        }
    }

    public function apply(array $manifest, int $actor, bool $allowSourceIssues = false, ?callable $progress = null): array
    {
        return $this->run($manifest, $actor, false, $allowSourceIssues, $progress);
    }

    private function run(array $input, int $actor, bool $preview, bool $allowSourceIssues, ?callable $progress): array
    {
        abort_unless($this->context->tenant?->database_status === 'ready', 503, 'База организации не подготовлена.');
        abort_unless($this->context->tenant?->status === 'active', 403, 'Организация неактивна.');
        abort_unless(in_array($this->context->membership($actor)?->role, ['owner', 'admin'], true) && User::whereKey($actor)->where('is_active', true)->exists(), 403, 'Импорт доступен действующему владельцу или администратору.');
        $manifest = $this->manifests->validate($input);
        $hash = ManifestHash::of($manifest);
        $db = DB::connection('tenant');
        abort_unless((int) $db->table('tenant_identity')->sole()->tenant_id === $this->context->id(), 409, 'Идентичность базы организации не совпадает.');

        return $this->writes->transaction(function () use ($manifest, $hash, $actor, $preview, $allowSourceIssues, $progress, $db) {
            $db->select("SELECT pg_advisory_xact_lock(hashtextextended('rline-dataset-import',0))");
            $existing = DatasetImportBatch::where('manifest_hash', $hash)->first();
            if ($existing) {
                return ['batch_id' => $existing->id, 'replayed' => true, 'preview' => $preview, ...$existing->summary];
            }
            abort_if(Equipment::without(['passportProjection'])->exists(), 409, 'Первичное наполнение не перезаписывает существующее оборудование. Повтор того же набора возвращает квитанцию.');
            $issues = $manifest['issues'] ?? [];
            if (! $preview && ! $allowSourceIssues && collect($issues)->contains(fn ($issue) => $issue['severity'] === 'error')) {
                throw ValidationException::withMessages(['issues' => 'В источнике есть ошибки. Просмотрите отчёт; для сохранения незавершённого источника нужно явно разрешить его ошибки.']);
            }
            $declaredSources = array_fill_keys(array_column($manifest['equipment'], 'source_id'), true);
            foreach ($issues as $issue) {
                if (isset($issue['source_id']) && ! isset($declaredSources[$issue['source_id']])) {
                    throw ValidationException::withMessages(['issues' => 'Ошибка источника ссылается на отсутствующую ЕО.']);
                }
            }
            $nodes = $manifest['nodes'];
            $order = ['enterprise' => 0, 'site' => 1, 'production' => 2];
            usort($nodes, fn ($a, $b) => ($order[$a['input']['kind'] ?? ''] ?? 99) <=> ($order[$b['input']['kind'] ?? ''] ?? 99));
            foreach ($nodes as $node) {
                $this->facilities->createNode($this->facilityInputs->validated($node['input'], 'node'), $actor, $node['id']);
            }
            foreach ($manifest['installations'] as $installation) {
                $this->facilities->createInstallation($this->facilityInputs->validated($installation['input'], 'installation'), $actor, $installation['id']);
            }
            $origins = [];
            $batchId = (string) Str::uuid();
            foreach ($manifest['equipment'] as $index => $record) {
                try {
                    $created = $this->equipmentInputs->validated($record['create']);
                    $model = $this->equipment->create($created, $actor, $record['id']);
                    $base = ['installation_id' => $created['installation_id'], 'passport' => $created['passport'], 'passport_qualifiers' => $created['passport_qualifiers'] ?? []];
                    $update = $base + ['version' => $model->version];
                    if (! empty($record['components'])) {
                        $update['structure'] = $record['components'];
                    }
                    if (! empty($record['operating_scopes'])) {
                        $update['operating_scopes'] = $record['operating_scopes'];
                    }
                    if (! empty($record['inspections'])) {
                        $primary = $model->inspections->firstWhere('is_primary', true);
                        $update['inspections'] = array_map(function ($inspection) use ($primary) {
                            $input = $inspection['input'];
                            if (($input['is_primary'] ?? false) && ! $primary) {
                                throw ValidationException::withMessages(['inspections' => 'Первичная диагностика отсутствует для этого профиля.']);
                            }

                            return [...$input, 'id' => ($input['is_primary'] ?? false) ? $primary->id : $inspection['requested_id']];
                        }, $record['inspections']);
                    }
                    if (count($update) > count($base) + 1) {
                        $model = $this->equipment->update($model->id, $this->equipmentInputs->validated($update, true), $actor);
                    }
                    $stages = [];
                    if (! empty($record['thickness']['cmls']) || ! empty($record['thickness']['stages'])) {
                        $stages = $this->stages($model, $record['thickness']['stages'] ?? []);
                        $writeStages = array_map(function ($stage) {
                            $stage['measurements'] = array_map(fn ($row) => array_intersect_key($row, array_flip(['cml_id', 'points'])), $stage['measurements']);

                            return $stage;
                        }, $stages);
                        $thickness = ['cmls' => $record['thickness']['cmls'] ?? [], 'stages' => $writeStages];
                        $model = $this->equipment->update($model->id, $this->equipmentInputs->validated($base + ['version' => $model->version, 'thickness' => $thickness], true), $actor);
                    }
                    app(ImportedValueOrigins::class)->record($model, $record, $stages, $batchId, $manifest['source']['kind']);
                    $origins[] = ['equipment_id' => $model->id, 'source_id' => $record['source_id'], 'input_signature' => ManifestHash::of($record), 'imported_version' => $model->version];
                    if ($progress) {
                        $progress($index + 1, count($manifest['equipment']), $record['source_id']);
                    }
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ['equipment.'.$index.'.'.$key => $messages])->all());
                }
            }
            $equipmentIds = array_column($origins, 'equipment_id');
            $sourceIds = array_column($origins, 'equipment_id', 'source_id');
            $counts = ['nodes' => count($nodes), 'installations' => count($manifest['installations']), 'equipment' => count($origins)];
            foreach (['components' => 'equipment_components', 'inspections' => 'equipment_inspections', 'cmls' => 'equipment_cmls', 'thickness_stages' => 'equipment_thickness_stages', 'measurements' => 'equipment_thickness_values'] as $key => $table) {
                $counts[$key] = $db->table($table)->whereIn('equipment_id', $equipmentIds)->count();
            }
            $counts['points'] = $db->table('equipment_thickness_points as p')->join('equipment_thickness_values as v', 'v.id', '=', 'p.measurement_id')->whereIn('v.equipment_id', $equipmentIds)->count();
            $counts['issues'] = count($issues);
            $report = ['manifest_hash' => $hash, 'source' => $manifest['source'], 'counts' => $counts, 'source_errors' => collect($issues)->where('severity', 'error')->count()];
            if ($preview) {
                throw new PreviewFinished(['batch_id' => null, 'replayed' => false, 'preview' => true, ...$report]);
            }
            $batch = new DatasetImportBatch;
            $batch->id = $batchId;
            $batch->forceFill(['source_kind' => $manifest['source']['kind'], 'source_version' => $manifest['source']['version'], 'label' => $manifest['source']['label'], 'source_snapshot_at' => $manifest['source']['snapshot_at'], 'manifest_hash' => $hash, 'manifest' => $manifest, 'summary' => $report, 'applied_by' => $actor, 'applied_at' => now()])->save();
            foreach (array_chunk($origins, 500) as $chunk) {
                $db->table('dataset_import_equipment')->insert(array_map(fn ($row) => ['batch_id' => $batch->id, ...$row], $chunk));
            }
            $issueRows = [];
            foreach ($issues as $issue) {
                $sourceId = $issue['source_id'] ?? null;
                if ($sourceId !== null && ! isset($sourceIds[$sourceId])) {
                    throw ValidationException::withMessages(['issues' => 'Ошибка источника ссылается на отсутствующую ЕО.']);
                }
                $issueRows[] = ['id' => (string) Str::uuid(), 'batch_id' => $batch->id, 'equipment_id' => $sourceId === null ? null : $sourceIds[$sourceId], 'source_path' => $issue['source_path'], 'kind' => $issue['kind'], 'severity' => $issue['severity'], 'source_value' => array_key_exists('source_value', $issue) ? json_encode($issue['source_value'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : null, 'message' => $issue['message']];
            }
            foreach (array_chunk($issueRows, 500) as $chunk) {
                $db->table('dataset_import_issues')->insert($chunk);
            }
            $this->writes->event('dataset_import', $batch, 'created', $actor, null, ['id' => $batch->id, ...$report]);

            return ['batch_id' => $batch->id, 'replayed' => false, 'preview' => false, ...$report];
        });
    }

    private function stages(Equipment $equipment, array $inputs): array
    {
        if (count(array_unique(array_column($inputs, 'source_date'))) !== count($inputs)) {
            throw ValidationException::withMessages(['thickness' => 'Исходная колонка замеров не должна повторяться.']);
        }
        $db = DB::connection('tenant');
        $inspections = $db->table('equipment_inspections')->where('equipment_id', $equipment->id)->get();
        $stages = $db->table('equipment_thickness_stages')->where('equipment_id', $equipment->id)->get()->keyBy('inspection_id');
        $result = [];
        foreach ($inputs as $input) {
            $matches = $input['source_date'] === '__prim' ? $inspections->where('is_primary', true) : $inspections->where('performed_on', $input['source_date'])->whereIn('kind', ['ТО-1', 'ТО-2', 'ЭПБ', 'УЗТ']);
            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['thickness' => 'Исходная дата связана с несколькими диагностиками; требуется явное сопоставление.']);
            }
            $inspection = $matches->first();
            $stage = $inspection ? $stages->get($inspection->id) : null;
            if (! $stage && $input['source_date'] === '__prim') {
                if (! collect($input['measurements'] ?? [])->contains(fn ($measurement) => count(array_filter($measurement['points'] ?? [], fn ($point) => $point !== null)) > 0)) {
                    continue;
                }
                throw ValidationException::withMessages(['thickness' => 'Замеры первичной диагностики не имеют первичного этапа.']);
            }
            $result[] = ['id' => $stage?->id ?? (string) Str::uuid(), ...($stage ? [] : ['origin' => 'manual']), 'measured_on' => $input['measured_on'], 'verified' => false, 'measurements' => $input['measurements'] ?? []];
        }

        return $result;
    }
}
