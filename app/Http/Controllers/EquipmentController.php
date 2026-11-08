<?php

namespace App\Http\Controllers;

use App\Domain\EquipmentDiagnostics;
use App\Domain\EquipmentFlowContacts;
use App\Domain\EquipmentReadQuery;
use App\Domain\EquipmentStructure;
use App\Domain\EquipmentThickness;
use App\Domain\TenantWrites;
use App\Http\Requests\EquipmentIndexRequest;
use App\Http\Requests\EquipmentRequest;
use App\Http\Requests\UpdateEquipmentRequest;
use App\Http\Resources\EquipmentResource;
use App\Models\Equipment;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EquipmentController extends Controller
{
    public function catalog()
    {
        return ['version' => '2026-11-07', 'profiles' => config('equipment.profiles'),
            'fields' => collect(config('equipment.passport'))->map(fn ($field, $key) => ['key' => $key] + array_diff_key($field, ['rules' => true]))->values()];
    }

    public function index(EquipmentIndexRequest $request)
    {
        $data = $request->validated();
        $query = app(EquipmentReadQuery::class)->query($data);

        return EquipmentResource::collection($query->paginate($data['per_page'] ?? 50)->withQueryString());
    }

    public function show(string $equipment)
    {
        return DB::connection('tenant')->transaction(function () use ($equipment) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return (new EquipmentResource(Equipment::with(['installation.parent.parent.parent', 'components', 'inspections'])->findOrFail($equipment)))->response();
        });
    }

    #[Response(status: 201, type: EquipmentResource::class)]
    #[IgnoreResponse(200)]
    public function store(EquipmentRequest $request, TenantWrites $writes)
    {
        return $writes->transaction(function () use ($request, $writes) {
            $data = $request->validated();
            $this->applicability($data['profile_code'], $data['passport']);
            $model = new Equipment($data);
            $model->forceFill(['created_by' => $request->user()->id, 'updated_by' => $request->user()->id])->save();
            app(EquipmentDiagnostics::class)->ensurePrimary($model);
            app(EquipmentThickness::class)->ensureStages($model);
            $writes->event('equipment', $model, 'created', $request->user()->id);

            return (new EquipmentResource($model->load(['installation.parent.parent.parent', 'components', 'inspections'])))->response()->setStatusCode(201);
        });
    }

    #[Response(status: 200, type: EquipmentResource::class)]
    public function update(UpdateEquipmentRequest $request, string $equipment, TenantWrites $writes)
    {
        return $writes->transaction(function () use ($request, $equipment, $writes) {
            $model = Equipment::whereKey($equipment)->lockForUpdate()->firstOrFail();
            $data = $request->validated();
            $writes->version($model, $data['version']);
            $this->applicability($model->profile_code, $data['passport']);
            $before = $model->load(['components', 'inspections'])->toArray();
            $flowChange = array_key_exists('flow_contacts', $data) || $model->installation_id !== $data['installation_id'];
            if ($flowChange) {
                $before['flows'] = app(EquipmentFlowContacts::class)->read($model);
            }
            if (array_key_exists('thickness', $data)) {
                $before['thickness'] = app(EquipmentThickness::class)->read($model);
            }
            if (array_key_exists('structure', $data)) {
                app(EquipmentStructure::class)->apply($model, $data['structure']);
            }
            if (array_key_exists('inspections', $data)) {
                app(EquipmentDiagnostics::class)->apply($model, $data['inspections']);
            }
            $checks = $model->checks ?? [];
            foreach (array_unique([...array_keys($model->passport), ...array_keys($data['passport'])]) as $key) {
                if (($model->passport[$key] ?? null) !== ($data['passport'][$key] ?? null)) {
                    unset($checks[$key]);
                }
            }
            foreach ($data['verification'] ?? [] as $key => $checked) {
                if ($checked) {
                    $value = $data['passport'][$key] ?? null;
                    if ($value === null || $value === '') {
                        throw ValidationException::withMessages(['verification.'.$key => 'Нельзя проверить незаполненное поле.']);
                    }
                    $checks[$key] = $checks[$key] ?? ['by' => $request->user()->id, 'at' => now()->toIso8601String()];
                } else {
                    unset($checks[$key]);
                }
            }
            $model->fill($data)->forceFill(['checks' => $checks, 'version' => $model->version + 1, 'updated_by' => $request->user()->id])->save();
            app(EquipmentDiagnostics::class)->ensurePrimary($model);
            app(EquipmentThickness::class)->ensureStages($model);
            if (array_key_exists('thickness', $data)) {
                app(EquipmentThickness::class)->apply($model, $data['thickness'], $request->user()->id);
            }
            $model->load(['components', 'inspections']);
            if ($flowChange) {
                app(EquipmentFlowContacts::class)->apply($model, $data['flow_contacts'] ?? [], $request->user()->id);
            }
            $after = $model->toArray();
            if ($flowChange) {
                $after['flows'] = app(EquipmentFlowContacts::class)->read($model);
            }
            if (array_key_exists('thickness', $data)) {
                $after['thickness'] = app(EquipmentThickness::class)->read($model);
            }
            $writes->event('equipment', $model, 'updated', $request->user()->id, $before, $after);

            return (new EquipmentResource($model->load('installation.parent.parent.parent')))->response();
        });
    }

    private function applicability(string $profileCode, array $passport): void
    {
        $group = config('equipment.profiles')[$profileCode]['g'];
        foreach ($passport as $key => $value) {
            $allowed = config('equipment.passport')[$key]['groups'] ?? null;
            if ($allowed && $value !== null && ! in_array($group, $allowed, true)) {
                throw ValidationException::withMessages(['passport.'.$key => 'Поле не применяется к выбранному профилю оборудования.']);
            }
        }
    }

    public function events(string $equipment)
    {
        Equipment::findOrFail($equipment);

        return DB::connection('tenant')->table('domain_events')->where('entity_type', 'equipment')->where('entity_id', $equipment)
            ->select(['id', 'action', 'actor_id', 'created_at'])->orderByDesc('id')->paginate(30);
    }
}
