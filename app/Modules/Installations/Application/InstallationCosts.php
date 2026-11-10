<?php

namespace App\Modules\Installations\Application;

use App\Modules\Installations\Infrastructure\InstallationCostRepository;
use App\Modules\Installations\Models\Installation;
use App\Modules\Shared\Domain\ValueEquality;
use App\Modules\Shared\Infrastructure\TenantWrites;
use Illuminate\Validation\ValidationException;

final class InstallationCosts
{
    public function __construct(private readonly TenantWrites $writes) {}

    public function update(string $installation, array $data, int $actorId): Installation
    {
        return $this->writes->transaction(function () use ($installation, $data, $actorId) {
            $model = Installation::whereKey($installation)->lockForUpdate()->firstOrFail();
            abort_unless($model->status === 'active', 422, 'Сначала восстановите установку из архива.');
            $this->writes->version($model, $data['version']);
            $before = $this->snapshot($model);
            $values = $model->cost_values ?? [];
            $checks = $model->cost_checks ?? [];
            $sources = $model->cost_sources ?? [];
            $actor = ['by' => $actorId, 'at' => now()->toIso8601String()];
            foreach ($data['values'] ?? [] as $key => $value) {
                if (! ValueEquality::same($values[$key] ?? null, $value)) {
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
            $model->forceFill(['version' => $model->version + 1, 'updated_by' => $actorId])->save();
            app(InstallationCostRepository::class)->save($model, $values, $sources, $checks);
            $this->writes->event('installation_cost', $model, 'updated', $actorId, $before, $this->snapshot($model));

            return $model;
        });

    }

    private function snapshot(Installation $model): array
    {
        return ['overrides' => $model->cost_values ?? [], 'sources' => $model->cost_sources ?? [], 'checks' => $model->cost_checks ?? []];
    }
}
