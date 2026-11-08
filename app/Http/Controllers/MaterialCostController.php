<?php

namespace App\Http\Controllers;

use App\Domain\CatalogHistory;
use App\Domain\CatalogRecords;
use App\Http\Requests\UpdateMaterialCostRequest;
use App\Http\Resources\MaterialCostResource;
use App\Http\Resources\SteelGradeEventResource;
use App\Models\MaterialCost;

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
