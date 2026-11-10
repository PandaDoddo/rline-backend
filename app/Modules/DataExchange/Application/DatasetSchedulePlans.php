<?php

namespace App\Modules\DataExchange\Application;

use App\Models\User;
use App\Modules\DataExchange\Domain\ManifestHash;
use App\Modules\DataExchange\Models\DatasetImportBatch;
use App\Modules\Diagnostics\Models\EquipmentInspection;
use App\Modules\Equipment\Application\EquipmentCommands;
use App\Modules\Equipment\Application\EquipmentInputValidation;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Installations\Models\Installation;
use App\Modules\Shared\Infrastructure\TenantWrites;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Add-only delta for an already imported demo. No existing inspection is modified. */
final class DatasetSchedulePlans
{
    public function run(array $input, int $actor, bool $preview = true): array
    {
        $context = app(TenantContext::class);
        abort_unless($context->tenant?->status === 'active' && User::whereKey($actor)->where('is_active', true)->exists()
            && in_array($context->membership($actor)?->role, ['owner', 'admin'], true), 403);
        abort_unless((int) DB::connection('tenant')->table('tenant_identity')->sole()->tenant_id === $context->id(), 409);
        $data = Validator::make($input, [
            'format' => 'required|in:old-schedule-plan-delta-v1', 'base_source_version' => 'required|string|max:100',
            'source' => 'required|array:kind,version,label,snapshot_at,checksums', 'source.kind' => 'required|in:demo_expanded',
            'source.version' => 'required|in:old-schedule-plan-delta-v1', 'source.label' => 'required|string|max:255',
            'source.snapshot_at' => 'required|date', 'source.checksums' => 'required|array|min:1', 'source.checksums.*' => 'required|regex:/^[0-9a-f]{64}$/D',
            'plans' => 'required|array|list|max:2000', 'plans.*' => 'required|array:id,source_id,kind,planned_on,source_path,generated',
            'plans.*.id' => 'required|uuid|distinct', 'plans.*.source_id' => 'required|string|max:100',
            'plans.*.kind' => 'required|in:ТО-1,ТО-2,ЭПБ,ГИ,ПИ,ГЕОД-И,НО,УЗТ,другое',
            'plans.*.planned_on' => 'required|date_format:Y-m-d', 'plans.*.source_path' => 'required|string|max:500', 'plans.*.generated' => 'required|accepted',
        ])->validate();
        try {
            return app(TenantWrites::class)->transaction(function () use ($data, $actor, $preview) {
                $db = DB::connection('tenant');
                $db->select("SELECT pg_advisory_xact_lock(hashtextextended('rline-dataset-import',0))");
                $hash = ManifestHash::of($data);
                if ($existing = DatasetImportBatch::where('manifest_hash', $hash)->first()) {
                    return ['preview' => $preview, 'replayed' => true, ...$existing->summary];
                }
                $base = DatasetImportBatch::where('source_version', $data['base_source_version'])->where('source_kind', 'demo_expanded')->sole();
                abort_unless(ManifestHash::of($base->manifest['source']['checksums']) === ManifestHash::of($data['source']['checksums']), 422, 'Дельта относится к другой версии old.');
                $origins = $db->table('dataset_import_equipment')->where('batch_id', $base->id)->get()->keyBy('source_id');
                // Follow the aggregate's installation -> equipment lock order.
                $installationIds = Equipment::without('passportProjection')->whereIn('id', $origins->pluck('equipment_id'))->pluck('installation_id');
                Installation::whereIn('id', $installationIds)->orderBy('id')->lockForUpdate()->get();
                $summary = ['equipment' => 0, 'plans' => 0, 'skipped' => 0];
                $evidence = [];
                foreach (collect($data['plans'])->groupBy('source_id') as $source => $plans) {
                    $origin = $origins->get($source);
                    abort_unless($origin, 422, 'Нет импортированной ЕО: '.$source);
                    $equipment = Equipment::whereKey($origin->equipment_id)->lockForUpdate()->firstOrFail();
                    if ($equipment->version !== (int) $origin->imported_version || $equipment->status !== 'active') {
                        $summary['skipped'] += count($plans);

                        continue;
                    }
                    $history = EquipmentInspection::where('equipment_id', $equipment->id)->lockForUpdate()->get();
                    $added = [];
                    foreach ($plans as $plan) {
                        $protected = $history->contains(fn ($row) => ($row->follow_up_dates[$plan['kind']] ?? null) !== null || in_array($plan['kind'], $row->unknown_fields, true)
                            || ($row->kind === $plan['kind'] && ($row->performed_on || $row->planned_on || $row->next_on || $row->verified_at || array_intersect(['planned_on', 'next_on'], $row->unknown_fields))));
                        if ($protected) {
                            $summary['skipped']++;

                            continue;
                        }
                        abort_if(EquipmentInspection::whereKey($plan['id'])->exists(), 409, 'Идентификатор плановой диагностики уже занят.');
                        $added[] = ['id' => $plan['id'], 'kind' => $plan['kind'], 'is_primary' => false, 'planned_on' => $plan['planned_on'],
                            'notes' => 'ДЕМО · сгенерированная плановая дата old; не факт выполнения. Источник: '.$plan['source_path'], 'verified' => false];
                    }
                    if (! $added) {
                        continue;
                    }
                    $update = app(EquipmentInputValidation::class)->validated(['installation_id' => $equipment->installation_id, 'passport' => $equipment->passport, 'passport_qualifiers' => $equipment->passport_qualifiers,
                        'version' => $equipment->version, 'inspections' => $added], true);
                    app(EquipmentCommands::class)->update($equipment->id, $update, $actor);
                    foreach ($added as $plan) {
                        $stored = EquipmentInspection::findOrFail($plan['id']);
                        abort_unless($stored->equipment_id === $equipment->id && $stored->planned_on?->format('Y-m-d') === $plan['planned_on'] && $stored->performed_on === null && $stored->verified_at === null, 409, 'Проверка записанной плановой даты не прошла.');
                    }
                    $summary['equipment']++;
                    $summary['plans'] += count($added);
                    $evidence[] = ['equipment_id' => $equipment->id, 'source_id' => $source, 'inspection_ids' => array_column($added, 'id')];
                }
                $receipt = new DatasetImportBatch;
                $receipt->forceFill(['id' => (string) Str::uuid(), 'source_kind' => 'demo_expanded', 'source_version' => $data['source']['version'],
                    'label' => $data['source']['label'], 'source_snapshot_at' => $data['source']['snapshot_at'], 'manifest_hash' => $hash,
                    'manifest' => $data, 'summary' => $summary + ['added' => $evidence], 'applied_by' => $actor, 'applied_at' => now()])->save();
                $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
                $report = ['preview' => $preview, 'replayed' => false, ...$summary];
                if ($preview) {
                    throw new PreviewFinished($report);
                }

                return $report;
            });
        } catch (PreviewFinished $finished) {
            return $finished->report;
        }
    }
}
