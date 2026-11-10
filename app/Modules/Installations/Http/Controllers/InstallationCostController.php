<?php

namespace App\Modules\Installations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogs\Application\CatalogHistory;
use App\Modules\Catalogs\Http\Resources\SteelGradeEventResource;
use App\Modules\DataExchange\Application\InstallationImportData;
use App\Modules\Installations\Application\InstallationCosts;
use App\Modules\Installations\Domain\InstallationCostFactors;
use App\Modules\Installations\Http\Requests\UpdateInstallationCostRequest;
use App\Modules\Installations\Http\Resources\InstallationCostResource;
use App\Modules\Installations\Models\Installation;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;

class InstallationCostController extends Controller
{
    public function schema(InstallationCostFactors $factors)
    {
        return ['fields' => collect(config('installation-costs.fields'))->map(fn ($field, $key) => ['key' => $key] + $field)->values(), 'tables' => $factors->tables()];
    }

    #[Response(status: 200, type: InstallationCostResource::class)]
    public function show(string $installation)
    {
        return DB::connection('tenant')->transaction(function () use ($installation) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $this->resourceResponse(Installation::findOrFail($installation));
        });
    }

    #[Response(status: 200, type: InstallationCostResource::class)]
    public function update(UpdateInstallationCostRequest $request, string $installation, InstallationCosts $costs)
    {
        return DB::connection('tenant')->transaction(fn () => $this->resourceResponse($costs->update($installation, $request->validated(), $request->user()->id)));
    }

    private function resourceResponse(Installation $model)
    {
        app(InstallationImportData::class)->describe($model);

        return (new InstallationCostResource($model))->response();
    }

    public function events(string $installation, CatalogHistory $history)
    {
        Installation::findOrFail($installation);

        return SteelGradeEventResource::collection($history->page('installation_cost', $installation, config('installation-costs.fields')));
    }
}
