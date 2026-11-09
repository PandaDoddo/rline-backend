<?php

namespace App\Modules\Installations\Application;

use App\Modules\Installations\Infrastructure\InstallationPassportRepository;
use App\Modules\Installations\Models\FacilityNode;
use App\Modules\Installations\Models\Installation;
use App\Modules\Shared\Infrastructure\TenantWrites;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Hierarchy writes shared by HTTP and maintenance imports. Authorization belongs to the entry point. */
final class FacilityCommands
{
    public function __construct(private readonly TenantWrites $writes, private readonly InstallationPassportRepository $passports) {}

    public function createNode(array $data, int $actor, ?string $identity = null): FacilityNode
    {
        return $this->writes->transaction(function () use ($data, $actor, $identity) {
            $this->parent($data['kind'], $data['parent_id'] ?? null);
            $model = new FacilityNode($data);
            $this->identity($model, $identity);
            $model->forceFill(['version' => 1, 'created_by' => $actor, 'updated_by' => $actor])->save();
            $this->writes->event('facility_node', $model, 'created', $actor);

            return $model;
        });
    }

    public function updateNode(string $id, array $data, int $actor): FacilityNode
    {
        if (array_key_exists('kind', $data)) {
            throw ValidationException::withMessages(['kind' => 'Вид узла дерева неизменен.']);
        }

        return $this->writes->transaction(function () use ($id, $data, $actor) {
            $model = FacilityNode::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($model->status === 'active', 422, 'Сначала восстановите узел из архива.');
            $this->writes->version($model, $data['version']);
            $this->parent($model->kind, array_key_exists('parent_id', $data) ? $data['parent_id'] : $model->parent_id);
            $before = $model->toArray();
            $model->fill($data)->forceFill(['version' => $model->version + 1, 'updated_by' => $actor])->save();
            $this->writes->event('facility_node', $model, 'updated', $actor, $before);

            return $model;
        });
    }

    public function createInstallation(array $data, int $actor, ?string $identity = null): Installation
    {
        return $this->writes->transaction(function () use ($data, $actor, $identity) {
            $this->parent('installation', $data['parent_id']);
            $model = new Installation($data);
            $this->identity($model, $identity);
            $model->forceFill(['version' => 1, 'created_by' => $actor, 'updated_by' => $actor])->save();
            if (array_key_exists('passport', $data)) {
                $this->passports->save($model, $data['passport']);
            }
            $this->writes->event('installation', $model, 'created', $actor);

            return $model;
        });
    }

    public function updateInstallation(string $id, array $data, int $actor): Installation
    {
        return $this->writes->transaction(function () use ($id, $data, $actor) {
            $model = Installation::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($model->status === 'active', 422, 'Сначала восстановите установку из архива.');
            $this->writes->version($model, $data['version']);
            $this->parent('installation', $data['parent_id']);
            $before = $model->toArray();
            $model->fill($data)->forceFill(['version' => $model->version + 1, 'updated_by' => $actor])->save();
            if (array_key_exists('passport', $data)) {
                $this->passports->save($model, $data['passport']);
            }
            $this->writes->event('installation', $model, 'updated', $actor, $before);

            return $model;
        });
    }

    private function identity(Model $model, ?string $identity): void
    {
        if ($identity === null) {
            return;
        }
        if (! Str::isUuid($identity)) {
            throw ValidationException::withMessages(['id' => 'Идентификатор должен быть UUID.']);
        }
        $model->forceFill(['id' => $identity]);
    }

    private function parent(string $kind, ?string $parentId): void
    {
        $expected = ['site' => 'enterprise', 'production' => 'site', 'installation' => 'production'][$kind] ?? null;
        if (($expected === null && $parentId !== null) || ($expected !== null && (! $parentId || ! FacilityNode::whereKey($parentId)->where('kind', $expected)->where('status', 'active')->lockForUpdate()->exists()))) {
            throw ValidationException::withMessages(['parent_id' => 'Размещение должно соответствовать дереву: предприятие → площадка → производство.']);
        }
    }
}
