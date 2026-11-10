<?php

namespace App\Modules\Catalogs\Application;

use App\Modules\Catalogs\Domain\CatalogValues;
use App\Modules\Catalogs\Domain\SubstanceValues;
use App\Modules\Catalogs\Models\Substance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Three-way update: no baseline rewrite, no browser stamps, no overwrite of local divergence. */
final class CatalogSnapshotUpdate
{
    public function run(array $snapshot, int $actor, bool $apply = false): array
    {
        return DB::connection('tenant')->transaction(function () use ($snapshot, $actor, $apply) {
            $report = ['snapshot' => $snapshot['version'], 'apply' => $apply, 'updated' => [], 'created' => [], 'conflicts' => [], 'invalid' => []];
            foreach ($snapshot['entries'] as $entry) {
                $model = Substance::where('legacy_code', $entry['code'])->lockForUpdate()->first();
                if (! $model) {
                    if ($entry['before'] !== null) {
                        $report['conflicts'][] = [$entry['code'], 'missing record'];

                        continue;
                    }
                    try {
                        $data = app(SubstanceInputValidation::class)->validated(['values' => $entry['after']]);
                        if (Substance::whereRaw('lower(label)=lower(?)', [$data['values']['name']])->exists()) {
                            $report['conflicts'][] = [$entry['code'], 'name already exists'];

                            continue;
                        }
                        if ($apply) {
                            $model = app(CatalogRecords::class)->create(Substance::class, $data, $actor, 'substance');
                            $model->forceFill(['legacy_code' => $entry['code']])->save();
                        }
                        $report['created'][] = $entry['code'];
                    } catch (ValidationException $error) {
                        $report['invalid'][] = [$entry['code'], $error->errors()];
                    }

                    continue;
                }
                $current = $model->effective();
                $changes = [];
                foreach ($entry['after'] as $key => $raw) {
                    $before = SubstanceValues::normalize($key, $entry['before'][$key] ?? null);
                    $after = SubstanceValues::normalize($key, $raw);
                    if (CatalogValues::same($before, $after) || CatalogValues::same($current[$key] ?? null, $after)) {
                        continue;
                    }
                    if (! CatalogValues::same($current[$key] ?? null, $before) || isset($model->checks[$key]) || isset($model->sources[$key]['citation'])) {
                        $report['conflicts'][] = [$entry['code'], $key, 'local value or verification preserved'];

                        continue;
                    }
                    try {
                        app(SubstanceInputValidation::class)->validated(['version' => $model->version, 'values' => [$key => $after]], true);
                        $changes[$key] = $after;
                    } catch (ValidationException $error) {
                        $report['invalid'][] = [$entry['code'], $key, $error->errors()];
                    }
                }
                $status = null;
                if (($entry['after']['idlhUnit'] ?? 'ppm') !== ($current['idlhUnit'] ?? 'ppm')) {
                    foreach (['idlh', 'idlhUnit'] as $key) {
                        $target = SubstanceValues::normalize($key, $entry['after'][$key] ?? null);
                        if (! array_key_exists($key, $changes) && ! CatalogValues::same($current[$key] ?? null, $target)) {
                            unset($changes['idlh'], $changes['idlhUnit'], $changes['idlhNote']);
                            $report['conflicts'][] = [$entry['code'], 'idlh group', 'unit/value pair preserved together'];
                            break;
                        }
                    }
                }
                if ($entry['archived'] && $model->status !== 'archived') {
                    // Archive is a whole-card decision; preserve independently maintained cards.
                    if ($model->checks === [] && array_reduce(array_keys($current), fn ($ok, $key) => $ok && CatalogValues::same($current[$key] ?? null, SubstanceValues::normalize($key, $entry['before'][$key] ?? null)), true)) {
                        $status = 'archived';
                    } else {
                        $report['conflicts'][] = [$entry['code'], 'status', 'local card preserved'];
                    }
                }
                if ($changes === [] && $status === null) {
                    continue;
                }
                $report['updated'][] = ['code' => $entry['code'], 'fields' => array_keys($changes), 'status' => $status];
                if ($apply) {
                    $sources = [];
                    foreach ($changes as $key => $_) {
                        $sources[$key] = ['citation' => 'Снимок каталога main '.$snapshot['to'].'; выгрузка Николая, 09–10.11.2026', 'conditions' => 'Серверная предметная проверка не переносилась из браузера.'];
                    }
                    app(CatalogRecords::class)->update(Substance::class, $model->id, ['version' => $model->version, 'values' => $changes, 'sources' => $sources] + ($status ? ['status' => $status] : []), $actor, 'substance');
                }
            }

            return $report;
        });
    }
}
