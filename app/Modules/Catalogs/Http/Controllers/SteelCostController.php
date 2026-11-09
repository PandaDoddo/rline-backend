<?php

namespace App\Modules\Catalogs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogs\Application\CatalogRecords;
use App\Modules\Catalogs\Http\Requests\UpdateSteelCostRequest;
use App\Modules\Catalogs\Http\Resources\SteelCostResource;
use App\Modules\Catalogs\Infrastructure\CatalogListProjection;
use App\Modules\Catalogs\Models\MaterialCost;
use App\Modules\Catalogs\Models\SteelGrade;
use Illuminate\Support\Facades\DB;

class SteelCostController extends Controller
{
    public function index()
    {
        // One repeatable snapshot, two reads; stress tables and raw cards stay in PostgreSQL.
        return DB::connection('tenant')->transaction(function () {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $rows = MaterialCost::all()->toArray();
            $grades = CatalogListProjection::steelCosts(SteelGrade::where('status', 'active')->whereRaw("lower(coalesce((base_values || overrides)->>'kind', 'сталь')) = ?", ['сталь']))->orderBy('label')->get();
            $grades->each(fn ($grade) => $grade->setRelation('materialCostRows', $rows));

            return SteelCostResource::collection($grades)->response();
        });
    }

    public function update(UpdateSteelCostRequest $request, string $steelGrade, CatalogRecords $records)
    {
        $model = $records->update(SteelGrade::class, $steelGrade, $request->validated(), $request->user()->id, 'steel_grade');
        $model->setRelation('materialCostRows', MaterialCost::all()->toArray());

        return new SteelCostResource($model);
    }
}
