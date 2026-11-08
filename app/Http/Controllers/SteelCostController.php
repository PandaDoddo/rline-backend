<?php

namespace App\Http\Controllers;

use App\Domain\CatalogListProjection;
use App\Domain\CatalogRecords;
use App\Http\Requests\UpdateSteelCostRequest;
use App\Http\Resources\SteelCostResource;
use App\Models\MaterialCost;
use App\Models\SteelGrade;
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
