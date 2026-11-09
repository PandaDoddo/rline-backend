<?php

namespace App\Modules\Installations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Installations\Application\FacilityCommands;
use App\Modules\Installations\Application\FacilityLifecycle;
use App\Modules\Installations\Http\Requests\FacilityIndexRequest;
use App\Modules\Installations\Http\Requests\FacilityLifecycleRequest;
use App\Modules\Installations\Http\Requests\FacilityNodeRequest;
use App\Modules\Installations\Http\Requests\InstallationRequest;
use App\Modules\Installations\Http\Requests\UpdateFacilityNodeRequest;
use App\Modules\Installations\Http\Requests\UpdateInstallationRequest;
use App\Modules\Installations\Http\Resources\FacilityNodeResource;
use App\Modules\Installations\Http\Resources\InstallationResource;
use App\Modules\Installations\Models\FacilityNode;
use App\Modules\Installations\Models\Installation;
use App\Modules\Shared\Infrastructure\TenantWrites;
use App\Tenancy\TenantContext;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FacilityController extends Controller
{
    private function ready(): void
    {
        abort_unless(app(TenantContext::class)->tenant?->database_status === 'ready', 503, 'База организации ещё не подготовлена.');
    }

    private function search(Request $request, Builder $query): Builder
    {
        $query->where('status', $request->input('status', 'active'));
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

    #[Response(status: 201, type: FacilityNodeResource::class)]
    #[IgnoreResponse(200)]
    public function createNode(FacilityNodeRequest $request, FacilityCommands $commands, TenantWrites $writes)
    {
        $this->ready();

        return $writes->transaction(fn () => (new FacilityNodeResource($commands->createNode($request->validated(), $request->user()->id)->load('parent.parent')))->response()->setStatusCode(201));
    }

    #[Response(status: 200, type: FacilityNodeResource::class)]
    public function updateNode(UpdateFacilityNodeRequest $request, string $node, FacilityCommands $commands, TenantWrites $writes)
    {
        $this->ready();

        return $writes->transaction(fn () => (new FacilityNodeResource($commands->updateNode($node, $request->validated(), $request->user()->id)->load('parent.parent')))->response());
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

    #[Response(status: 201, type: InstallationResource::class)]
    #[IgnoreResponse(200)]
    public function store(InstallationRequest $request, FacilityCommands $commands, TenantWrites $writes)
    {
        $this->ready();

        return $writes->transaction(fn () => (new InstallationResource($commands->createInstallation($request->validated(), $request->user()->id)->load('parent.parent.parent')))->response()->setStatusCode(201));
    }

    #[Response(status: 200, type: InstallationResource::class)]
    public function update(UpdateInstallationRequest $request, string $installation, FacilityCommands $commands, TenantWrites $writes)
    {
        $this->ready();

        return $writes->transaction(fn () => (new InstallationResource($commands->updateInstallation($installation, $request->validated(), $request->user()->id)->load('parent.parent.parent')))->response());
    }

    public function events(Request $request, string $installation)
    {
        $this->ready();
        Installation::findOrFail($installation);
        $request->validate(['page' => 'sometimes|integer|min:1']);

        return DB::connection('tenant')->table('domain_events')->where('entity_type', 'installation')->where('entity_id', $installation)
            ->select(['id', 'action', 'actor_id', 'created_at'])->orderByDesc('id')->paginate(30);
    }

    public function dependencies(string $installation, FacilityLifecycle $lifecycle)
    {
        return $lifecycle->dependencies(Installation::findOrFail($installation));
    }

    public function lifecycle(FacilityLifecycleRequest $request, string $installation, FacilityLifecycle $lifecycle)
    {
        return new InstallationResource($lifecycle->installation($installation, $request->validated(), $request->user()->id)->load('parent.parent.parent'));
    }

    public function destroy(FacilityLifecycleRequest $request, string $installation, FacilityLifecycle $lifecycle)
    {
        $lifecycle->installation($installation, $request->validated(), $request->user()->id, true);

        return response()->noContent();
    }

    public function nodeLifecycle(FacilityLifecycleRequest $request, string $node, FacilityLifecycle $lifecycle)
    {
        return new FacilityNodeResource($lifecycle->node($node, $request->validated(), $request->user()->id)->load('parent.parent'));
    }

    public function destroyNode(FacilityLifecycleRequest $request, string $node, FacilityLifecycle $lifecycle)
    {
        $lifecycle->node($node, $request->validated(), $request->user()->id, true);

        return response()->noContent();
    }
}
