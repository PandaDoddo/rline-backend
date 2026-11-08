<?php

namespace App\Http\Controllers;

use App\Http\Requests\FacilityIndexRequest;
use App\Http\Requests\FacilityNodeRequest;
use App\Http\Requests\InstallationRequest;
use App\Http\Requests\UpdateFacilityNodeRequest;
use App\Http\Requests\UpdateInstallationRequest;
use App\Http\Resources\FacilityNodeResource;
use App\Http\Resources\InstallationResource;
use App\Models\FacilityNode;
use App\Models\Installation;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacilityController extends Controller
{
    private function ready(): void
    {
        abort_unless(app(TenantContext::class)->tenant?->database_status === 'ready', 503, 'База организации ещё не подготовлена.');
    }

    private function search(Request $request, Builder $query): Builder
    {
        $data = $request->validate(['q' => 'sometimes|nullable|string|max:255', 'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $q = trim($data['q'] ?? '');
        if ($q !== '') {
            $query->where(function ($query) use ($q) {
                $pattern = '%'.addcslashes($q, '\\%_').'%';
                $query->whereRaw('name ILIKE ?', [$pattern])->orWhereRaw('code ILIKE ?', [$pattern]);
                foreach (['parent', 'parent.parent', 'parent.parent.parent'] as $relation) {
                    $query->orWhereHas($relation, fn ($parent) => $parent->whereRaw('name ILIKE ?', [$pattern]));
                }
            });
        }

        return $query->orderBy('name')->orderBy('id');
    }

    public function nodes(FacilityIndexRequest $request)
    {
        $this->ready();
        $data = $request->validate(['kind' => 'sometimes|in:enterprise,site,production', 'parent_id' => 'sometimes|nullable|uuid']);
        $query = FacilityNode::with('parent.parent');
        if (isset($data['kind'])) {
            $query->where('kind', $data['kind']);
        }
        if (array_key_exists('parent_id', $data)) {
            $query->where('parent_id', $data['parent_id']);
        }

        return FacilityNodeResource::collection($this->search($request, $query)->paginate($request->integer('per_page', 30))->withQueryString());
    }

    public function createNode(FacilityNodeRequest $request)
    {
        $this->ready();
        $node = $this->write(function () use ($request) {
            $data = $request->validated();
            $this->parent($data['kind'], $data['parent_id'] ?? null);
            $node = new FacilityNode($data);
            $node->forceFill(['version' => 1, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id])->save();
            $this->audit($node, 'created', null, $request);

            return $node;
        });

        return (new FacilityNodeResource($node->load('parent.parent')))->response()->setStatusCode(201);
    }

    public function updateNode(UpdateFacilityNodeRequest $request, string $node)
    {
        $this->ready();
        $model = $this->write(function () use ($request, $node) {
            $model = FacilityNode::whereKey($node)->lockForUpdate()->firstOrFail();
            $data = $request->validated();
            $this->version($model, $data['version']);
            $this->parent($model->kind, array_key_exists('parent_id', $data) ? $data['parent_id'] : $model->parent_id);
            $before = $model->toArray();
            $model->fill($data)->forceFill(['version' => $model->version + 1, 'updated_by' => $request->user()->id])->save();
            $this->audit($model, 'updated', $before, $request);

            return $model;
        });

        return new FacilityNodeResource($model->load('parent.parent'));
    }

    private function parent(string $kind, ?string $parentId): void
    {
        $expected = ['site' => 'enterprise', 'production' => 'site'][$kind] ?? null;
        if (($expected === null && $parentId !== null) || ($expected !== null && (! $parentId || ! FacilityNode::whereKey($parentId)->where('kind', $expected)->exists()))) {
            throw ValidationException::withMessages(['parent_id' => 'Размещение должно соответствовать дереву: предприятие → площадка → производство.']);
        }
    }

    public function index(FacilityIndexRequest $request)
    {
        $this->ready();
        $data = $request->validate(['parent_id' => 'sometimes|uuid']);
        $query = Installation::with('parent.parent.parent');
        if (isset($data['parent_id'])) {
            $query->where('parent_id', $data['parent_id']);
        }

        return InstallationResource::collection($this->search($request, $query)->paginate($request->integer('per_page', 30))->withQueryString());
    }

    public function show(string $installation)
    {
        $this->ready();

        return new InstallationResource(Installation::with('parent.parent.parent')->findOrFail($installation));
    }

    public function store(InstallationRequest $request)
    {
        $this->ready();
        $model = $this->write(function () use ($request) {
            $model = new Installation($request->validated());
            $model->forceFill(['version' => 1, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id])->save();
            $this->audit($model, 'created', null, $request);

            return $model;
        });

        return (new InstallationResource($model->load('parent.parent.parent')))->response()->setStatusCode(201);
    }

    public function update(UpdateInstallationRequest $request, string $installation)
    {
        $this->ready();
        $model = $this->write(function () use ($request, $installation) {
            $model = Installation::whereKey($installation)->lockForUpdate()->firstOrFail();
            $data = $request->validated();
            $this->version($model, $data['version']);
            $before = $model->toArray();
            $model->fill($data)->forceFill(['version' => $model->version + 1, 'updated_by' => $request->user()->id])->save();
            $this->audit($model, 'updated', $before, $request);

            return $model;
        });

        return new InstallationResource($model->load('parent.parent.parent'));
    }

    public function events(Request $request, string $installation)
    {
        $this->ready();
        Installation::findOrFail($installation);
        $request->validate(['page' => 'sometimes|integer|min:1']);

        return DB::connection('tenant')->table('domain_events')->where('entity_type', 'installation')->where('entity_id', $installation)
            ->select(['id', 'action', 'actor_id', 'created_at'])->orderByDesc('id')->paginate(30);
    }

    private function version(Model $model, int $version): void
    {
        abort_if($model->version !== $version, 409, 'Запись изменена другим пользователем. Обновите данные и повторите правку.');
    }

    private function audit(Model $model, string $action, ?array $before, Request $request): void
    {
        DB::connection('tenant')->table('domain_events')->insert(['entity_type' => $model instanceof Installation ? 'installation' : 'facility_node',
            'entity_id' => $model->id, 'action' => $action, 'actor_id' => $request->user()->id,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($model->toArray(), JSON_THROW_ON_ERROR)]);
    }

    private function write(callable $action): mixed
    {
        try {
            return DB::connection('tenant')->transaction($action);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['code' => 'Код уже используется в этой организации.']);
            }
            throw $exception;
        }
    }
}
