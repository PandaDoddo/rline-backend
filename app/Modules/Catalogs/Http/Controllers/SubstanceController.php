<?php

namespace App\Modules\Catalogs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogs\Application\CatalogHistory;
use App\Modules\Catalogs\Application\CatalogRecords;
use App\Modules\Catalogs\Http\Requests\SubstanceIndexRequest;
use App\Modules\Catalogs\Http\Requests\SubstanceRequest;
use App\Modules\Catalogs\Http\Requests\UpdateSubstanceRequest;
use App\Modules\Catalogs\Http\Resources\SubstanceEventResource;
use App\Modules\Catalogs\Http\Resources\SubstanceResource;
use App\Modules\Catalogs\Http\Resources\SubstanceSummaryResource;
use App\Modules\Catalogs\Infrastructure\CatalogListProjection;
use App\Modules\Catalogs\Infrastructure\SubstanceCardProjection;
use App\Modules\Catalogs\Models\Substance;
use App\Modules\DataExchange\Application\ImportedRecordOrigins;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;

class SubstanceController extends Controller
{
    public function schema()
    {
        return ['fields' => collect(config('substances.fields'))->map(fn ($field, $key) => ['key' => $key] + $field)->values(), 'missing_values' => config('substances.missing_values'),
            'baseline_notice' => 'Базовая версия перенесена из исходного каталога фронтенда; серверные отметки проверки независимы.'];
    }

    public function index(SubstanceIndexRequest $request)
    {
        $data = $request->validated();
        $query = Substance::where('status', $data['status'] ?? 'active');
        if (isset($data['origin'])) {
            $query->where('origin', $data['origin']);
        }
        $effective = SubstanceCardProjection::effectiveSql();
        if (($q = trim($data['q'] ?? '')) !== '') {
            $pattern = '%'.addcslashes($q, '\\%_').'%';
            $query->where(function ($query) use ($pattern, $effective) {
                $query->whereRaw('label ILIKE ?', [$pattern]);
                foreach (['formula', 'cas', 'intlName', 'intlSyn'] as $key) {
                    $query->orWhereRaw($effective."->>'".$key."' ILIKE ?", [$pattern]);
                }
            });
        }
        if (isset($data['group'])) {
            $query->whereRaw($effective."->>'grpRF' = ?", [$data['group']]);
        }
        if (! empty($data['phases'])) {
            $query->whereIn(DB::raw($effective."->>'phaseNu'"), $data['phases']);
        }
        foreach ($data['criteria'] ?? [] as $criterion) {
            $query->whereRaw($effective."->>'".$criterion."' = 'true'");
        }
        if (isset($data['criterion'])) {
            $key = $data['criterion'];
            $value = $data['criterion_value'] ?? 'yes';
            if ($value === 'unknown') {
                $query->whereRaw($effective."->>'".$key."' IS NULL");
            } else {
                $query->whereRaw('('.$effective."->>'".$key."')::boolean = ?", [$value === 'yes']);
            }
        }

        return SubstanceSummaryResource::collection(CatalogListProjection::substances($query)->orderBy('label')->orderBy('id')->paginate($data['per_page'] ?? 50));
    }

    #[Response(status: 200, type: SubstanceResource::class)]
    public function show(string $substance)
    {
        return DB::connection('tenant')->transaction(function () use ($substance) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $this->resourceResponse(Substance::findOrFail($substance));
        });
    }

    #[Response(status: 201, type: SubstanceResource::class)]
    #[IgnoreResponse(200)]
    public function store(SubstanceRequest $request, CatalogRecords $records)
    {
        return DB::connection('tenant')->transaction(fn () => $this->resourceResponse($records->create(Substance::class, $request->validated(), $request->user()->id, 'substance'), 201));
    }

    #[Response(status: 200, type: SubstanceResource::class)]
    public function update(UpdateSubstanceRequest $request, string $substance, CatalogRecords $records)
    {
        return DB::connection('tenant')->transaction(fn () => $this->resourceResponse($records->update(Substance::class, $substance, $request->validated(), $request->user()->id, 'substance')));
    }

    private function resourceResponse(Substance $model, int $status = 200)
    {
        app(ImportedRecordOrigins::class)->attach(collect([$model]), 'substance');

        return (new SubstanceResource($model))->response()->setStatusCode($status);
    }

    public function events(string $substance, CatalogHistory $history)
    {
        Substance::findOrFail($substance);

        return SubstanceEventResource::collection($history->page('substance', $substance, config('substances.fields')));
    }
}
