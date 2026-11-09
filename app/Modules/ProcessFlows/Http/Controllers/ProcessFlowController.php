<?php

namespace App\Modules\ProcessFlows\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogs\Application\CatalogHistory;
use App\Modules\Catalogs\Http\Resources\SteelGradeEventResource;
use App\Modules\Installations\Models\Installation;
use App\Modules\ProcessFlows\Application\ProcessFlows;
use App\Modules\ProcessFlows\Domain\FlowScreening;
use App\Modules\ProcessFlows\Domain\ProcessFlowHistory;
use App\Modules\ProcessFlows\Http\Requests\InitializeProcessFlowsRequest;
use App\Modules\ProcessFlows\Http\Requests\ProcessFlowIndexRequest;
use App\Modules\ProcessFlows\Http\Requests\ProcessFlowNodeRequest;
use App\Modules\ProcessFlows\Http\Requests\UpdateProcessFlowNodeRequest;
use App\Modules\ProcessFlows\Http\Requests\UpdateProcessFlowRequest;
use App\Modules\ProcessFlows\Http\Resources\ProcessFlowCalculationResource;
use App\Modules\ProcessFlows\Http\Resources\ProcessFlowCalculationSummaryResource;
use App\Modules\ProcessFlows\Http\Resources\ProcessFlowNodeResource;
use App\Modules\ProcessFlows\Http\Resources\ProcessFlowResource;
use App\Modules\ProcessFlows\Infrastructure\FlowNodeProjection;
use App\Modules\ProcessFlows\Models\ProcessFlowCalculation;
use App\Modules\ProcessFlows\Models\ProcessFlowNode;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;

class ProcessFlowController extends Controller
{
    public function schema()
    {
        // The versioned screening baseline is shared by all flow cards.
        return ['screening' => app(FlowScreening::class)->schema(), 'categories' => config('process-flows.categories'), 'media' => config('process-flows.media'), 'fields' => collect(config('process-flows.fields'))->map(fn ($field, $key) => ['key' => $key] + $field)->values(), 'impurity_units' => config('process-flows.impurity_units')];
    }

    public function index(ProcessFlowIndexRequest $request, string $installation)
    {
        return DB::connection('tenant')->transaction(function () use ($request, $installation) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $model = Installation::findOrFail($installation);
            $data = $request->validated();
            $query = ProcessFlowNode::without(['cardProjection', 'screeningProjection'])->where('installation_id', $installation)->where('status', $data['status'] ?? 'active');
            if (($q = trim($data['q'] ?? '')) !== '') {
                $query->where('name', 'ILIKE', '%'.addcslashes($q, '\\%_').'%');
            } elseif (! ($data['flat'] ?? false)) {
                $query->where('parent_id', $data['parent_id'] ?? null);
            }
            if (isset($data['kind'])) {
                $query->where('kind', $data['kind']);
            }
            if (isset($data['category'])) {
                $query->where('category', $data['category']);
            }
            $ancestors = [];
            $parent = $data['parent_id'] ?? null;
            while ($parent !== null && count($ancestors) < 8) {
                $node = ProcessFlowNode::without(['cardProjection', 'screeningProjection'])->whereKey($parent)->where('installation_id', $installation)->firstOrFail();
                array_unshift($ancestors, (new ProcessFlowNodeResource($node))->toArray($request));
                $parent = $node->parent_id;
            }
            $query->select(['id', 'installation_id', 'parent_id', 'kind', 'category', 'name', 'medium_kind', 'status', 'version'])
                ->selectRaw('(SELECT count(*) FROM process_flow_nodes children WHERE children.parent_id = process_flow_nodes.id AND children.status = ?) AS children_count', [$data['status'] ?? 'active']);
            FlowNodeProjection::path($query);

            return ProcessFlowNodeResource::collection($query->orderBy('kind')->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 50))->additional([
                'structure_version' => (int) $model->flow_structure_version, 'setup' => $model->flow_setup, 'initialized' => $model->flow_setup !== null || ProcessFlowNode::where('installation_id', $installation)->exists(), 'ancestors' => $ancestors,
            ])->response();
        });
    }

    public function initialize(InitializeProcessFlowsRequest $request, string $installation, ProcessFlows $flows)
    {
        $model = $flows->initialize($installation, $request->validated(), $request->user()->id);

        return ['structure_version' => (int) $model->flow_structure_version, 'setup' => $model->flow_setup];
    }

    #[Response(status: 201, type: ProcessFlowNodeResource::class)]
    public function store(ProcessFlowNodeRequest $request, string $installation, ProcessFlows $flows)
    {
        return (new ProcessFlowNodeResource($flows->createNode($installation, $request->validated(), $request->user()->id)))->response()->setStatusCode(201);
    }

    public function updateNode(UpdateProcessFlowNodeRequest $request, string $node, ProcessFlows $flows)
    {
        return new ProcessFlowNodeResource($flows->updateNode($node, $request->validated(), $request->user()->id));
    }

    #[Response(status: 200, type: ProcessFlowResource::class)]
    public function show(string $node)
    {
        return DB::connection('tenant')->transaction(function () use ($node) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return (new ProcessFlowResource(ProcessFlowNode::findOrFail($node)))->response();
        });
    }

    public function update(UpdateProcessFlowRequest $request, string $node, ProcessFlows $flows)
    {
        return new ProcessFlowResource($flows->updateCard($node, $request->validated(), $request->user()->id));
    }

    public function calculations(string $node)
    {
        ProcessFlowNode::findOrFail($node);

        return ProcessFlowCalculationSummaryResource::collection(ProcessFlowCalculation::where('flow_id', $node)->select(['id', 'signature', 'engine_version', 'created_by', 'created_at'])->orderByDesc('created_at')->orderByDesc('id')->paginate(20));
    }

    public function calculation(string $node, string $calculation)
    {
        ProcessFlowNode::findOrFail($node);

        return new ProcessFlowCalculationResource(ProcessFlowCalculation::where('flow_id', $node)->findOrFail($calculation));
    }

    public function events(string $node, CatalogHistory $history, ProcessFlowHistory $changes)
    {
        ProcessFlowNode::findOrFail($node);

        return SteelGradeEventResource::collection($history->page('process_flow', $node, $changes->fields(), [$changes, 'changes']));
    }
}
