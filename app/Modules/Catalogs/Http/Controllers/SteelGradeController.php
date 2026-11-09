<?php

namespace App\Modules\Catalogs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogs\Application\CatalogHistory;
use App\Modules\Catalogs\Application\CatalogRecords;
use App\Modules\Catalogs\Application\SteelGradeAssessment;
use App\Modules\Catalogs\Domain\SteelGradeHistory;
use App\Modules\Catalogs\Http\Requests\SteelGradeIndexRequest;
use App\Modules\Catalogs\Http\Requests\SteelGradeRequest;
use App\Modules\Catalogs\Http\Requests\UpdateSteelGradeRequest;
use App\Modules\Catalogs\Http\Resources\SteelGradeEventResource;
use App\Modules\Catalogs\Http\Resources\SteelGradeResource;
use App\Modules\Catalogs\Http\Resources\SteelGradeSummaryResource;
use App\Modules\Catalogs\Infrastructure\CatalogListProjection;
use App\Modules\Catalogs\Models\SteelGrade;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;

class SteelGradeController extends Controller
{
    public function schema()
    {
        return ['fields' => collect(config('steel-grades.fields'))->map(fn ($field, $key) => ['key' => $key] + $field)->values(), 'missing_values' => config('steel-grades.missing_values'),
            'baseline_notice' => '33 марки перенесены из исходного марочника; J/X̄ и ASME требуют предметной приёмки. Историческое matcost не применяется.'];
    }

    public function index(SteelGradeIndexRequest $request)
    {
        $data = $request->validated();
        $query = SteelGrade::where('status', $data['status'] ?? 'active');
        if (isset($data['origin'])) {
            $query->where('origin', $data['origin']);
        }
        $effective = '(base_values || overrides)';
        if (($q = trim($data['q'] ?? '')) !== '') {
            $pattern = '%'.addcslashes($q, '\\%_').'%';
            $query->where(function ($query) use ($pattern, $effective) {
                $query->whereRaw('label ILIKE ?', [$pattern]);
                foreach (['analog', 'aisi', 'en', 'std'] as $key) {
                    $query->orWhereRaw($effective."->>'".$key."' ILIKE ?", [$pattern]);
                }
            });
        }

        return SteelGradeSummaryResource::collection(CatalogListProjection::steelGrades($query)->orderBy('label')->orderBy('id')->paginate($data['per_page'] ?? 50));
    }

    public function show(string $steelGrade)
    {
        return new SteelGradeResource(SteelGrade::findOrFail($steelGrade));
    }

    #[Response(status: 201, type: SteelGradeResource::class)]
    #[IgnoreResponse(200)]
    public function store(SteelGradeRequest $request, CatalogRecords $records)
    {
        $model = $records->create(SteelGrade::class, $request->validated(), $request->user()->id, 'steel_grade', [app(SteelGradeAssessment::class), 'validateChanged']);

        return (new SteelGradeResource($model))->response()->setStatusCode(201);
    }

    #[Response(status: 200, type: SteelGradeResource::class)]
    public function update(UpdateSteelGradeRequest $request, string $steelGrade, CatalogRecords $records)
    {
        $model = $records->update(SteelGrade::class, $steelGrade, $request->validated(), $request->user()->id, 'steel_grade', [app(SteelGradeAssessment::class), 'validateChanged']);

        return (new SteelGradeResource($model))->response();
    }

    public function events(string $steelGrade, CatalogHistory $history)
    {
        SteelGrade::findOrFail($steelGrade);

        return SteelGradeEventResource::collection($history->page('steel_grade', $steelGrade, config('steel-grades.fields'), [app(SteelGradeHistory::class), 'changes']));
    }
}
