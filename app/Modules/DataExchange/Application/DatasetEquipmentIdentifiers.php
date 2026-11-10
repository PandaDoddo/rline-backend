<?php

namespace App\Modules\DataExchange\Application;

use App\Models\User;
use App\Modules\DataExchange\Domain\ManifestHash;
use App\Modules\DataExchange\Models\DatasetImportBatch;
use App\Modules\Equipment\Application\EquipmentCommands;
use App\Modules\Equipment\Application\EquipmentInputValidation;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Installations\Models\Installation;
use App\Modules\Shared\Infrastructure\TenantWrites;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Repair missing imported identifiers only; current passports remain authoritative. */
final class DatasetEquipmentIdentifiers
{
    public function run(array $input, int $actor, bool $preview = true): array
    {
        $context = app(TenantContext::class);
        abort_unless($context->tenant?->status === 'active' && User::whereKey($actor)->where('is_active', true)->exists()
            && in_array($context->membership($actor)?->role, ['owner', 'admin'], true), 403);
        $db = DB::connection('tenant');
        abort_unless((int) $db->table('tenant_identity')->sole()->tenant_id === $context->id(), 409);
        $data = Validator::make($input, [
            'format' => 'required|in:old-equipment-identifiers-v1', 'base_source_version' => 'required|string|max:100',
            'source' => 'required|array:kind,version,label,snapshot_at,checksums', 'source.kind' => 'required|in:demo_expanded',
            'source.version' => 'required|in:old-equipment-identifiers-v1', 'source.label' => 'required|string|max:255',
            'source.snapshot_at' => 'required|date', 'source.checksums' => 'required|array|min:1', 'source.checksums.*' => 'required|regex:/^[0-9a-f]{64}$/D',
            'records' => 'required|array|list|max:2000', 'records.*' => 'required|array:source_id,tag',
            'records.*.source_id' => 'required|string|max:100|distinct', 'records.*.tag' => 'required|string|max:255',
        ])->validate();
        try {
            return app(TenantWrites::class)->transaction(function () use ($data, $actor, $preview, $db) {
                $db->select("SELECT pg_advisory_xact_lock(hashtextextended('rline-dataset-import',0))");
                $hash = ManifestHash::of($data);
                if ($existing = DatasetImportBatch::where('manifest_hash', $hash)->first()) {
                    return ['preview' => $preview, 'replayed' => true, ...$existing->summary];
                }
                $base = DatasetImportBatch::where('source_version', $data['base_source_version'])->where('source_kind', 'demo_expanded')->sole();
                abort_unless(ManifestHash::of($base->manifest['source']['checksums']) === ManifestHash::of($data['source']['checksums']), 422);
                $origins = $db->table('dataset_import_equipment')->where('batch_id', $base->id)->get()->keyBy('source_id');
                $installations = Equipment::without('passportProjection')->whereIn('id', $origins->pluck('equipment_id'))->pluck('installation_id');
                Installation::whereIn('id', $installations)->orderBy('id')->lockForUpdate()->get();
                $summary = ['updated' => 0, 'skipped' => 0];
                foreach ($data['records'] as $record) {
                    $origin = $origins->get($record['source_id']);
                    abort_unless($origin, 422, 'Нет импортированной ЕО: '.$record['source_id']);
                    $equipment = Equipment::whereKey($origin->equipment_id)->lockForUpdate()->firstOrFail();
                    $field = $equipment->profile_code === 'ppk' ? 'protected_equipment_position' : 'tag';
                    $changedTag = $db->table('domain_events')->where('entity_type', 'equipment')->where('entity_id', $equipment->id)
                        ->whereRaw('COALESCE(("before"->>\'version\')::bigint,0) >= ?', [(int) $origin->imported_version])
                        ->whereRaw("(\"before\"->'passport'->>?) IS DISTINCT FROM (\"after\"->'passport'->>?)", [$field, $field])->exists();
                    if ($equipment->status !== 'active' || trim((string) ($equipment->passport[$field] ?? '')) !== '' || isset($equipment->passport_qualifiers[$field]['state']) || isset($equipment->checks[$field]) || $changedTag) {
                        $summary['skipped']++;

                        continue;
                    }
                    $update = app(EquipmentInputValidation::class)->validated(['installation_id' => $equipment->installation_id, 'version' => $equipment->version,
                        'passport' => [...$equipment->passport, $field => $record['tag']], 'passport_qualifiers' => $equipment->passport_qualifiers], true);
                    $saved = app(EquipmentCommands::class)->update($equipment->id, $update, $actor);
                    abort_unless($saved->passport[$field] === $record['tag'], 409);
                    $summary['updated']++;
                }
                (new DatasetImportBatch)->forceFill(['id' => (string) Str::uuid(), 'source_kind' => 'demo_expanded', 'source_version' => $data['source']['version'],
                    'label' => $data['source']['label'], 'source_snapshot_at' => $data['source']['snapshot_at'], 'manifest_hash' => $hash,
                    'manifest' => $data, 'summary' => $summary, 'applied_by' => $actor, 'applied_at' => now()])->save();
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
