<?php

namespace App\Http\Controllers;

use App\Domain\CatalogHistory;
use App\Domain\CatalogValues;
use App\Domain\InstallationCostFactors;
use App\Domain\TenantWrites;
use App\Http\Requests\UpdateInstallationCostRequest;
use App\Http\Resources\InstallationCostResource;
use App\Http\Resources\SteelGradeEventResource;
use App\Models\Installation;
use Illuminate\Validation\ValidationException;

class InstallationCostController extends Controller
{
    public function schema(InstallationCostFactors $factors)
    {
        return ['fields' => collect(config('installation-costs.fields'))->map(fn ($field, $key) => ['key' => $key] + $field)->values(), 'tables' => $factors->tables()];
    }

    public function show(string $installation)
    {
        return new InstallationCostResource(Installation::findOrFail($installation));
    }

    public function update(UpdateInstallationCostRequest $request, string $installation, TenantWrites $writes)
    {
        $model = $writes->transaction(function () use ($request, $installation, $writes) {
            $model = Installation::whereKey($installation)->lockForUpdate()->firstOrFail();
            $data = $request->validated();
            $writes->version($model, $data['version']);
            $before = $this->snapshot($model);
            $values = $model->cost_values ?? [];
            $checks = $model->cost_checks ?? [];
            $sources = $model->cost_sources ?? [];
            $actor = ['by' => $request->user()->id, 'at' => now()->toIso8601String()];
            foreach ($data['values'] ?? [] as $key => $value) {
                if (! CatalogValues::same($values[$key] ?? null, $value)) {
                    unset($checks[$key]);
                    $sources[$key] = ['citation' => config('installation-costs.fields.'.$key.'.source'), 'conditions' => null] + $actor;
                }
                $values[$key] = $value;
            }
            foreach ($data['sources'] ?? [] as $key => $source) {
                $sources[$key] = ['citation' => config('installation-costs.fields.'.$key.'.source') ?? $source['citation'] ?? null, 'conditions' => $source['conditions'] ?? null] + $actor;
                unset($checks[$key]);
            }
            foreach ($data['verification'] ?? [] as $key => $checked) {
                if (! $checked) {
                    unset($checks[$key]);
                } elseif (($values[$key] ?? null) === null) {
                    throw ValidationException::withMessages(['verification.'.$key => 'Нельзя проверить незаполненное свойство.']);
                } else {
                    $checks[$key] ??= $actor;
                }
            }
            $model->forceFill(['cost_values' => $values, 'cost_checks' => $checks, 'cost_sources' => $sources, 'version' => $model->version + 1, 'updated_by' => $request->user()->id])->save();
            $writes->event('installation_cost', $model, 'updated', $request->user()->id, $before, $this->snapshot($model));

            return $model;
        });

        return new InstallationCostResource($model);
    }

    public function events(string $installation, CatalogHistory $history)
    {
        Installation::findOrFail($installation);

        return SteelGradeEventResource::collection($history->page('installation_cost', $installation, config('installation-costs.fields')));
    }

    private function snapshot(Installation $model): array
    {
        return ['overrides' => $model->cost_values ?? [], 'sources' => $model->cost_sources ?? [], 'checks' => $model->cost_checks ?? []];
    }
}
