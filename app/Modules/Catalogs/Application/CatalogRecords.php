<?php

namespace App\Modules\Catalogs\Application;

use App\Modules\Catalogs\Domain\CatalogValues;
use App\Modules\Shared\Infrastructure\TenantWrites;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CatalogRecords
{
    public function __construct(private TenantWrites $writes) {}

    /** @param class-string<Model> $modelClass */
    public function create(string $modelClass, array $data, int $actor, string $entity, ?callable $validate = null): Model
    {
        return $this->writes->transaction(function () use ($modelClass, $data, $actor, $entity, $validate) {
            $values = $data['values'];
            $this->name($values);
            if ($validate) {
                $validate($values, $values);
            }
            $sources = [];
            foreach (array_unique([...array_keys($values), ...array_keys($data['sources'] ?? [])]) as $key) {
                $sources[$key] = $this->source($data['sources'][$key] ?? [], $actor);
            }
            $model = (new $modelClass(['label' => $values['name'], 'overrides' => $values]))->forceFill(['sources' => $sources, 'created_by' => $actor, 'updated_by' => $actor]);
            $model->save();
            $this->writes->event($entity, $model, 'created', $actor);

            return $model;
        });
    }

    /** @param class-string<Model> $modelClass */
    public function update(string $modelClass, string $id, array $data, int $actor, string $entity, ?callable $validate = null): Model
    {
        return $this->writes->transaction(function () use ($modelClass, $id, $data, $actor, $entity, $validate) {
            $model = $modelClass::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->writes->version($model, $data['version']);
            $before = $model->toArray();
            $old = array_replace($model->base_values, $model->overrides);
            $overrides = $model->overrides;
            $sources = $model->sources;
            $checks = $model->checks;
            foreach ($data['reset_fields'] ?? [] as $key) {
                unset($overrides[$key], $sources[$key], $checks[$key]);
            }
            foreach ($data['values'] ?? [] as $key => $value) {
                $overrides[$key] = $value;
                if (! CatalogValues::same($old[$key] ?? null, $value)) {
                    unset($checks[$key]);
                    $sources[$key] = $this->source([], $actor);
                }
            }
            foreach ($data['sources'] ?? [] as $key => $source) {
                $sources[$key] = $this->source($source, $actor);
                unset($checks[$key]);
            }
            $effective = array_replace($model->base_values, $overrides);
            $this->name($effective);
            if ($validate) {
                $validate($effective, $data['values'] ?? []);
            }
            foreach ($data['verification'] ?? [] as $key => $checked) {
                if (! $checked) {
                    unset($checks[$key]);

                    continue;
                }
                $value = $effective[$key] ?? null;
                if ($value === null || $value === '' || $value === [] || is_array($value) && array_key_exists('rows', $value) && empty($value['rows'])) {
                    throw ValidationException::withMessages(['verification.'.$key => 'Нельзя проверить незаполненное свойство.']);
                }
                $checks[$key] ??= ['by' => $actor, 'at' => now()->toIso8601String()];
            }
            $model->forceFill(['label' => $effective['name'], 'overrides' => $overrides, 'sources' => $sources, 'checks' => $checks, 'status' => $data['status'] ?? $model->status, 'version' => $model->version + 1, 'updated_by' => $actor])->save();
            $this->writes->event($entity, $model, 'updated', $actor, $before);

            return $model;
        });
    }

    private function name(array $values): void
    {
        if (! is_string($values['name'] ?? null) || trim($values['name']) === '') {
            throw ValidationException::withMessages(['values.name' => 'Наименование обязательно.']);
        }
    }

    private function source(array $source, int $actor): array
    {
        return ['citation' => $source['citation'] ?? null, 'conditions' => $source['conditions'] ?? null, 'by' => $actor, 'at' => now()->toIso8601String()];
    }
}
