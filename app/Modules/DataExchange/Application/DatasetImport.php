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
use App\Modules\Installations\Application\InstallationCostInputValidation;
use App\Modules\Installations\Application\InstallationCosts;
use App\Modules\Installations\Application\InstallationMaintenance;
use App\Modules\Installations\Application\InstallationMaintenanceInputValidation;
use App\Modules\Installations\Models\InstallationMaintenanceRecord;
use App\Modules\Rbi\Application\InstallationManagement;
use App\Modules\Rbi\Application\InstallationRiskCriteria;
use App\Modules\Rbi\Application\ManagementInputValidation;
use App\Modules\Rbi\Application\RiskTargetInputValidation;
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
            $declaredFlows = array_fill_keys(array_column($manifest['flow_nodes'] ?? [], 'source_id'), true);
            $declaredInstallations = [];
            foreach ($manifest['installations'] as $installation) {
                $source = $installation['source_id'] ?? $installation['id'];
                if (isset($declaredInstallations[$source])) {
                    throw ValidationException::withMessages(['installations' => 'Идентификатор источника установки повторяется.']);
                }
                $declaredInstallations[$source] = $installation['id'];
            }
            foreach ($issues as $issue) {
                if (count(array_filter(['source_id', 'flow_source_id', 'installation_source_id'], fn ($key) => isset($issue[$key]))) > 1) {
                    throw ValidationException::withMessages(['issues' => 'Замечание источника должно иметь не более одного владельца.']);
                }
                if (isset($issue['installation_source_id']) && ! isset($declaredInstallations[$issue['installation_source_id']])) {
                    throw ValidationException::withMessages(['issues' => 'Замечание установки ссылается на отсутствующего владельца.']);
                }
                if (isset($issue['flow_source_id']) && ! isset($declaredFlows[$issue['flow_source_id']])) {
                    throw ValidationException::withMessages(['issues' => 'Замечание потока должно иметь одного существующего владельца.']);
                }
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
            $costFieldCount = 0;
            $managementAnswerCount = 0;
            $maintenanceCount = 0;
            $riskTargetCount = 0;
            $batchId = (string) Str::uuid();
            $installationReceipts = [];
            $maintenanceReceipts = [];
            $installationReadback = [];
            foreach ($manifest['installations'] as $index => $installation) {
                $installationInput = $this->facilityInputs->validated($installation['input'], 'installation');
                $model = $this->facilities->createInstallation($installationInput, $actor, $installation['id']);
                $expected = ['id' => $model->id, 'input' => $installationInput, 'costs' => null, 'management' => null, 'maintenance' => null, 'risk_criteria' => null];
                if (isset($installation['costs'])) {
                    try {
                        $data = app(InstallationCostInputValidation::class)->validated(['version' => $model->version, ...$installation['costs']]);
                        $expected['costs'] = $data;
                        $model = app(InstallationCosts::class)->update($model->id, $data, $actor);
                        $costFieldCount += count($data['values'] ?? []);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ['installations.'.$index.'.costs.'.$key => $messages])->all());
                    }
                }
                $installationVersion = $model->version;
                if (isset($installation['management'])) {
                    try {
                        $data = app(ManagementInputValidation::class)->validated(['version' => $model->version, ...$installation['management']]);
                        $expected['management'] = $data;
                        $assessment = app(InstallationManagement::class)->update($model->id, $data, $actor, $this->writes);
                        $installationVersion = $assessment['version'];
                        $managementAnswerCount += count($data['answers']);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ['installations.'.$index.'.management.'.$key => $messages])->all());
                    }
                }
                if (isset($installation['maintenance'])) {
                    $records = $installation['maintenance']['records'] ?? [];
                    $references = array_map(fn ($row) => $row['source_reference'] ?? 'maintenance/'.$row['id'], $records);
                    if (count($references) !== count(array_unique($references))) {
                        throw ValidationException::withMessages(['installations.'.$index.'.maintenance.records' => 'Ссылка источника обслуживания повторяется в одной установке.']);
                    }
                    try {
                        $payload = ['version' => $installationVersion, 'upserts' => array_map(fn ($row) => ['id' => $row['id'], 'kind' => $row['kind'], 'values' => $row['values'], 'verified' => false], $records)];
                        if (array_key_exists('no_stops', $installation['maintenance'])) {
                            $payload['no_stops'] = $installation['maintenance']['no_stops'];
                        }
                        $data = app(InstallationMaintenanceInputValidation::class)->validated($payload);
                        $expected['maintenance'] = $data;
                        $updated = app(InstallationMaintenance::class)->update($model->id, $data, $actor);
                        $installationVersion = $updated->version;
                        $maintenanceCount += count($records);
                        $versions = InstallationMaintenanceRecord::without('dataProjection')->whereIn('id', array_column($records, 'id'))->pluck('version', 'id');
                        foreach ($records as $record) {
                            $maintenanceReceipts[] = ['record_id' => $record['id'], 'installation_id' => $model->id, 'kind' => $record['kind'], 'batch_id' => $batchId, 'source_reference' => $record['source_reference'] ?? 'maintenance/'.$record['id'], 'source_verified' => $record['source_verified'] ?? null, 'imported_version' => $versions[$record['id']]];
                        }
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ['installations.'.$index.'.maintenance.'.preg_replace('/^upserts(?=\.|$)/', 'records', $key) => $messages])->all());
                    }
                }
                if (isset($installation['risk_criteria'])) {
                    try {
                        $data = app(RiskTargetInputValidation::class)->validated(['version' => $installationVersion, ...$installation['risk_criteria']]);
                        $expected['risk_criteria'] = $data;
                        $criteria = app(InstallationRiskCriteria::class)->update($model->id, $data, $actor);
                        $installationVersion = $criteria['version'];
                        $riskTargetCount += count($data['values'] ?? []);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ['installations.'.$index.'.risk_criteria.'.$key => $messages])->all());
                    }
                }
                $installationReadback[] = $expected;
                $installationReceipts[] = ['installation_id' => $model->id, 'batch_id' => $batchId, 'source_id' => $installation['source_id'] ?? $installation['id'], 'imported_version' => $installationVersion];
            }
            $substanceCount = app(DatasetSubstances::class)->import($manifest['substances'] ?? [], $actor, $batchId);
            $flowCounts = app(DatasetFlows::class)->import($manifest['flow_nodes'] ?? [], array_column($manifest['installations'], 'id'), $actor, $batchId);
            $flowIds = $flowCounts['source_ids'];
            unset($flowCounts['source_ids']);
            $origins = [];
            $readback = [];
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
                    foreach (['flow_selection', 'flow_contacts'] as $part) {
                        if (array_key_exists($part, $record)) {
                            $update[$part] = $record[$part];
                        }
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
                    $canonicalThickness = ['cmls' => [], 'stages' => []];
                    if (! empty($record['thickness']['cmls']) || ! empty($record['thickness']['stages'])) {
                        $stages = $this->stages($model, $record['thickness']['stages'] ?? []);
                        $writeStages = array_map(function ($stage) {
                            $stage['measurements'] = array_map(fn ($row) => array_intersect_key($row, array_flip(['cml_id', 'points'])), $stage['measurements']);

                            return $stage;
                        }, $stages);
                        $thickness = ['cmls' => $record['thickness']['cmls'] ?? [], 'stages' => $writeStages];
                        $thicknessData = $this->equipmentInputs->validated($base + ['version' => $model->version, 'thickness' => $thickness], true);
                        $canonicalThickness = $thicknessData['thickness'];
                        $model = $this->equipment->update($model->id, $thicknessData, $actor);
                    }
                    $canonicalVisual = null;
                    if (array_key_exists('visual_inspections', $record)) {
                        $visual = app(DatasetVisualInspections::class)->input($model, $record['visual_inspections']);
                        $visualData = $this->equipmentInputs->validated($base + ['version' => $model->version, 'visual_inspections' => $visual], true);
                        $canonicalVisual = $visualData['visual_inspections'];
                        $model = $this->equipment->update($model->id, $visualData, $actor);
                    }
                    $canonicalMechanisms = null;
                    if (array_key_exists('mechanisms', $record)) {
                        $mechanismData = $this->equipmentInputs->validated($base + ['version' => $model->version, 'mechanisms' => $record['mechanisms']], true);
                        $canonicalMechanisms = $mechanismData['mechanisms'];
                        $model = $this->equipment->update($model->id, $mechanismData, $actor);
                    }
                    $canonicalCategories = null;
                    if (array_key_exists('inspection_categories', $record)) {
                        $categoryInput = app(DatasetInspectionCategories::class)->input($model, $record['inspection_categories']);
                        $categoryData = $this->equipmentInputs->validated($base + ['version' => $model->version, 'inspection_categories' => $categoryInput], true);
                        $canonicalCategories = $categoryData['inspection_categories'];
                        $model = $this->equipment->update($model->id, $categoryData, $actor);
                    }
                    app(ImportedValueOrigins::class)->record($model, $record, $stages, $batchId, $manifest['source']['kind']);
                    $origins[] = ['equipment_id' => $model->id, 'source_id' => $record['source_id'], 'input_signature' => ManifestHash::of($record), 'imported_version' => $model->version];
                    $readback[] = ['id' => $model->id, 'source_id' => $record['source_id'], 'create' => $created, 'cmls' => $canonicalThickness['cmls'], 'stages' => $canonicalThickness['stages'], 'visual_inspections' => $canonicalVisual, 'inspection_categories' => $canonicalCategories, 'mechanisms' => $canonicalMechanisms];
                    if ($progress) {
                        $progress($index + 1, count($manifest['equipment']), $record['source_id']);
                    }
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ['equipment.'.$index.'.'.$key => $messages])->all());
                }
            }
            $equipmentIds = array_column($origins, 'equipment_id');
            $sourceIds = array_column($origins, 'equipment_id', 'source_id');
            $counts = ['nodes' => count($nodes), 'installations' => count($manifest['installations']), 'installation_cost_fields' => $costFieldCount, 'management_answers' => $managementAnswerCount, 'maintenance_records' => $maintenanceCount, 'risk_target_fields' => $riskTargetCount, 'equipment' => count($origins), 'substances' => $substanceCount, ...$flowCounts];
            foreach (['mechanism_inputs' => 'equipment_mechanism_inputs', 'component_mechanism_inputs' => 'equipment_component_mechanism_inputs', 'components' => 'equipment_components', 'inspections' => 'equipment_inspections', 'cmls' => 'equipment_cmls', 'thickness_stages' => 'equipment_thickness_stages', 'measurements' => 'equipment_thickness_values', 'inspection_category_stages' => 'equipment_inspection_category_stages', 'inspection_category_observations' => 'equipment_inspection_category_observations', 'visual_stages' => 'equipment_visual_stages', 'visual_observations' => 'equipment_visual_observations', 'visual_insulation_overrides' => 'equipment_visual_insulation_overrides', 'flow_selections' => 'equipment_flow_selections', 'flow_contacts' => 'equipment_flow_contacts'] as $key => $table) {
                $counts[$key] = $db->table($table)->whereIn('equipment_id', $equipmentIds)->count();
            }
            $counts['points'] = $db->table('equipment_thickness_points as p')->join('equipment_thickness_values as v', 'v.id', '=', 'p.measurement_id')->whereIn('v.equipment_id', $equipmentIds)->count();
            $counts['flow_links'] = $db->table('equipment_flow_links as l')->join('equipment_flow_contacts as c', 'c.id', '=', 'l.contact_id')->whereIn('c.equipment_id', $equipmentIds)->count();
            $counts['issues'] = count($issues);
            $report = ['manifest_hash' => $hash, 'source' => $manifest['source'], 'counts' => $counts, 'source_errors' => collect($issues)->where('severity', 'error')->count()];
            $batch = new DatasetImportBatch;
            $batch->id = $batchId;
            $batch->forceFill(['source_kind' => $manifest['source']['kind'], 'source_version' => $manifest['source']['version'], 'label' => $manifest['source']['label'], 'source_snapshot_at' => $manifest['source']['snapshot_at'], 'manifest_hash' => $hash, 'manifest' => $manifest, 'summary' => $report, 'applied_by' => $actor, 'applied_at' => now()])->save();
            foreach (array_chunk($installationReceipts, 500) as $chunk) {
                $db->table('dataset_import_installations')->insert($chunk);
            }
            foreach (array_chunk($maintenanceReceipts, 500) as $chunk) {
                $db->table('dataset_import_maintenance')->insert($chunk);
            }
            foreach (array_chunk($origins, 500) as $chunk) {
                $db->table('dataset_import_equipment')->insert(array_map(fn ($row) => ['batch_id' => $batch->id, ...$row], $chunk));
            }
            $issueRows = [];
            foreach ($issues as $issue) {
                $sourceId = $issue['source_id'] ?? null;
                $flowSourceId = $issue['flow_source_id'] ?? null;
                if ($sourceId !== null && ! isset($sourceIds[$sourceId])) {
                    throw ValidationException::withMessages(['issues' => 'Ошибка источника ссылается на отсутствующую ЕО.']);
                }
                $issueRows[] = ['id' => (string) Str::uuid(), 'batch_id' => $batch->id, 'equipment_id' => $sourceId === null ? null : $sourceIds[$sourceId], 'flow_id' => $flowSourceId === null ? null : $flowIds[$flowSourceId], 'installation_id' => isset($issue['installation_source_id']) ? $declaredInstallations[$issue['installation_source_id']] : null, 'source_path' => $issue['source_path'], 'kind' => $issue['kind'], 'severity' => $issue['severity'], 'source_value' => array_key_exists('source_value', $issue) ? json_encode($issue['source_value'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : null, 'message' => $issue['message']];
            }
            foreach (array_chunk($issueRows, 500) as $chunk) {
                $db->table('dataset_import_issues')->insert($chunk);
            }
            $this->writes->event('dataset_import', $batch, 'created', $actor, null, ['id' => $batch->id, ...$report]);

            // Receipt summary is immutable; verification below describes this fresh run only.
            // Validate the complete graph, then independently reload canonical values.
            $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $report['verification'] = app(DatasetReadback::class)->equipment($readback) + app(DatasetReadback::class)->installations($installationReadback);
            if ($preview) {
                throw new PreviewFinished(['batch_id' => null, 'replayed' => false, 'preview' => true, ...$report]);
            }

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
