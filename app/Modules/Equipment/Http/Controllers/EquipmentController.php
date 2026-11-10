<?php

namespace App\Modules\Equipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Application\EquipmentOrigins;
use App\Modules\Diagnostics\Application\DiagnosticPlans;
use App\Modules\Diagnostics\Domain\InspectionCategorySchema;
use App\Modules\Diagnostics\Domain\VisualInspectionSchema;
use App\Modules\Equipment\Application\ComponentSpecifications;
use App\Modules\Equipment\Application\EquipmentCommands;
use App\Modules\Equipment\Application\EquipmentReadQuery;
use App\Modules\Equipment\Domain\ComponentDetailSchema;
use App\Modules\Equipment\Domain\OperatingScopeSchema;
use App\Modules\Equipment\Domain\Screening\ComponentScreeningSchema;
use App\Modules\Equipment\Http\Requests\EquipmentIndexRequest;
use App\Modules\Equipment\Http\Requests\EquipmentRequest;
use App\Modules\Equipment\Http\Requests\UpdateEquipmentRequest;
use App\Modules\Equipment\Http\Resources\ComponentTypeResource;
use App\Modules\Equipment\Http\Resources\EquipmentResource;
use App\Modules\Equipment\Models\ComponentType;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Shared\Infrastructure\TenantWrites;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;

class EquipmentController extends Controller
{
    public function catalog()
    {
        return ['mechanism_schema' => ComponentScreeningSchema::metadata(), 'inspection_category_schema' => InspectionCategorySchema::metadata(), 'visual_inspection_schema' => VisualInspectionSchema::metadata(), 'version' => '2026-11-09-component-types-v1', 'geometry_fields' => ComponentDetailSchema::metadata(), 'component_types' => ComponentTypeResource::collection(ComponentType::orderBy('position')->get()), 'profiles' => config('equipment.profiles'), 'operating_scope_fields' => collect(OperatingScopeSchema::fields())->map(fn ($field) => array_diff_key($field, ['rules' => true]))->values(),
            'fields' => collect(config('equipment.passport'))->map(fn ($field, $key) => ['key' => $key] + array_diff_key($field, ['rules' => true]))->values()];
    }

    public function index(EquipmentIndexRequest $request)
    {
        $data = $request->validated();
        $query = app(EquipmentReadQuery::class)->query($data);

        $page = $query->paginate($data['per_page'] ?? 50)->withQueryString();
        $plans = app(DiagnosticPlans::class)->read($page->getCollection()->pluck('id')->all());
        foreach ($page->getCollection() as $equipment) {
            $equipment->setAttribute('schedule_diagnostics', $plans[$equipment->id] ?? []);
        }

        app(EquipmentOrigins::class)->attach($page->getCollection());

        return EquipmentResource::collection($page);
    }

    public function show(string $equipment)
    {
        return DB::connection('tenant')->transaction(function () use ($equipment) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $this->resource(Equipment::with(['installation.parent.parent.parent', ...ComponentSpecifications::eagerLoads(), 'inspections'])->findOrFail($equipment))->response();
        });
    }

    #[Response(status: 201, type: EquipmentResource::class)]
    #[IgnoreResponse(200)]
    public function store(EquipmentRequest $request, TenantWrites $writes, EquipmentCommands $commands)
    {
        return $writes->transaction(fn () => $this->resource($commands->create($request->validated(), $request->user()->id))->response()->setStatusCode(201));
    }

    #[Response(status: 200, type: EquipmentResource::class)]
    public function update(UpdateEquipmentRequest $request, string $equipment, TenantWrites $writes, EquipmentCommands $commands)
    {
        return $writes->transaction(fn () => $this->resource($commands->update($equipment, $request->validated(), $request->user()->id))->response());
    }

    private function resource(Equipment $equipment): EquipmentResource
    {
        app(EquipmentOrigins::class)->attach(collect([$equipment]));

        return new EquipmentResource($equipment);
    }

    public function events(string $equipment)
    {
        Equipment::findOrFail($equipment);

        return DB::connection('tenant')->table('domain_events')->where('entity_type', 'equipment')->where('entity_id', $equipment)
            ->select(['id', 'action', 'actor_id', 'created_at'])->orderByDesc('id')->paginate(30);
    }
}
