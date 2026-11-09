<?php

namespace App\Modules\Catalogs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogs\Application\CatalogHistory;
use App\Modules\Catalogs\Application\CatalogRecords;
use App\Modules\Catalogs\Http\Requests\UpdateMaterialCostRequest;
use App\Modules\Catalogs\Http\Resources\MaterialCostResource;
use App\Modules\Catalogs\Http\Resources\SteelGradeEventResource;
use App\Modules\Catalogs\Models\MaterialCost;

class MaterialCostController extends Controller
{
    public function index()
    {
        return MaterialCostResource::collection(MaterialCost::orderBy('position')->get());
    }

    public function update(UpdateMaterialCostRequest $request, string $materialCost, CatalogRecords $records)
    {
        return new MaterialCostResource($records->update(MaterialCost::class, $materialCost, $request->validated(), $request->user()->id, 'material_cost'));
    }

    public function events(string $materialCost, CatalogHistory $history)
    {
        MaterialCost::findOrFail($materialCost);

        return SteelGradeEventResource::collection($history->page('material_cost', $materialCost, config('material-costs.fields')));
    }
}
