<?php

namespace App\Http\Controllers;

use App\Domain\CatalogHistory;
use App\Domain\CatalogListProjection;
use App\Domain\CatalogRecords;
use App\Http\Requests\SubstanceIndexRequest;
use App\Http\Requests\SubstanceRequest;
use App\Http\Requests\UpdateSubstanceRequest;
use App\Http\Resources\SubstanceEventResource;
use App\Http\Resources\SubstanceResource;
use App\Http\Resources\SubstanceSummaryResource;
use App\Models\Substance;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;

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
        $effective = '(base_values || overrides)';
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

    public function show(string $substance)
    {
        return new SubstanceResource(Substance::findOrFail($substance));
    }

    #[Response(status: 201, type: SubstanceResource::class)]
    #[IgnoreResponse(200)]
    public function store(SubstanceRequest $request, CatalogRecords $records)
    {
        $model = $records->create(Substance::class, $request->validated(), $request->user()->id, 'substance');

        return (new SubstanceResource($model))->response()->setStatusCode(201);
    }

    #[Response(status: 200, type: SubstanceResource::class)]
    public function update(UpdateSubstanceRequest $request, string $substance, CatalogRecords $records)
    {
        $model = $records->update(Substance::class, $substance, $request->validated(), $request->user()->id, 'substance');

        return (new SubstanceResource($model))->response();
    }

    public function events(string $substance, CatalogHistory $history)
    {
        Substance::findOrFail($substance);

        return SubstanceEventResource::collection($history->page('substance', $substance, config('substances.fields')));
    }
}
