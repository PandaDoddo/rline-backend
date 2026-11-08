<?php

namespace App\Http\Controllers;

use App\Domain\CatalogHistory;
use App\Domain\InstallationMaintenance;
use App\Domain\InstallationMaintenanceSummary;
use App\Domain\MaintenanceHistory;
use App\Http\Requests\InstallationMaintenanceIndexRequest;
use App\Http\Requests\UpdateInstallationMaintenanceRequest;
use App\Http\Resources\InstallationMaintenanceResource;
use App\Http\Resources\InstallationMaintenanceSummaryResource;
use App\Http\Resources\SteelGradeEventResource;
use App\Models\Installation;
use App\Models\InstallationMaintenanceRecord;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;

class InstallationMaintenanceController extends Controller
{
    public function index(InstallationMaintenanceIndexRequest $request, string $installation)
    {
        return DB::connection('tenant')->transaction(function () use ($request, $installation) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $model = Installation::findOrFail($installation);
            $data = $request->validated();
            $query = InstallationMaintenanceRecord::where('installation_id', $installation)->where('kind', $data['kind'])->select('installation_maintenance_records.*');
            if ($data['kind'] === 'repair') {
                $query->selectRaw("(SELECT count(*) FROM (SELECT i.equipment_id, i.kind, max(i.performed_on) AS performed_on FROM equipment_inspections i JOIN equipment e ON e.id = i.equipment_id WHERE e.installation_id = installation_maintenance_records.installation_id GROUP BY i.equipment_id, i.kind) latest WHERE latest.performed_on BETWEEN (installation_maintenance_records.values->>'starts_on')::date AND (installation_maintenance_records.values->>'ends_on')::date) AS performed_inspections");
            }
            $rows = $query->orderByRaw("coalesce(installation_maintenance_records.values->>'effective_date', installation_maintenance_records.values->>'starts_on', installation_maintenance_records.values->>'starts_at') DESC NULLS LAST")->orderBy('id')->paginate($data['per_page'] ?? 50);

            return InstallationMaintenanceResource::collection($rows)->additional(['installation_version' => (int) $model->version, 'no_stops' => $model->no_stops])->response();
        });
    }

    #[Response(status: 200, type: InstallationMaintenanceSummaryResource::class)]
    public function summary(string $installation, InstallationMaintenanceSummary $summary)
    {
        return DB::connection('tenant')->transaction(function () use ($installation, $summary) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return (new InstallationMaintenanceSummaryResource((object) $summary->describe(Installation::findOrFail($installation))))->response();
        });
    }

    public function update(UpdateInstallationMaintenanceRequest $request, string $installation, InstallationMaintenance $maintenance)
    {
        $model = $maintenance->update($installation, $request->validated(), $request->user()->id);

        return ['id' => (string) $model->id, 'version' => (int) $model->version];
    }

    public function events(string $installation, CatalogHistory $history, MaintenanceHistory $changes)
    {
        Installation::findOrFail($installation);

        return SteelGradeEventResource::collection($history->page('installation_maintenance', $installation, ['entries' => ['label' => 'Записи'], 'no_stops' => ['label' => 'Остановов не было']], [$changes, 'changes']));
    }
}
