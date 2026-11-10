<?php

namespace App\Modules\Installations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogs\Application\CatalogHistory;
use App\Modules\Catalogs\Http\Resources\SteelGradeEventResource;
use App\Modules\DataExchange\Application\ImportedRecordOrigins;
use App\Modules\Installations\Application\InstallationMaintenance;
use App\Modules\Installations\Application\InstallationMaintenanceSummary;
use App\Modules\Installations\Domain\MaintenanceHistory;
use App\Modules\Installations\Http\Requests\InstallationMaintenanceIndexRequest;
use App\Modules\Installations\Http\Requests\UpdateInstallationMaintenanceRequest;
use App\Modules\Installations\Http\Resources\InstallationMaintenanceResource;
use App\Modules\Installations\Http\Resources\InstallationMaintenanceSummaryResource;
use App\Modules\Installations\Models\Installation;
use App\Modules\Installations\Models\InstallationMaintenanceRecord;
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
            [$table,$dateColumn] = match ($data['kind']) {
                'mri' => ['installation_mri_parameters', 'effective_date'],'repair' => ['installation_repair_parameters', 'starts_on'],'stop' => ['installation_stop_parameters', 'starts_at']
            };
            $query = InstallationMaintenanceRecord::where('installation_maintenance_records.installation_id', $installation)->where('installation_maintenance_records.kind', $data['kind'])->leftJoin($table.' as p', 'p.record_id', '=', 'installation_maintenance_records.id')->select('installation_maintenance_records.*');
            if ($data['kind'] === 'repair') {
                $query->selectRaw('(SELECT count(*) FROM (SELECT i.equipment_id,i.kind,max(i.performed_on) AS performed_on FROM equipment_inspections i JOIN equipment e ON e.id=i.equipment_id WHERE e.installation_id=installation_maintenance_records.installation_id GROUP BY i.equipment_id,i.kind) latest WHERE latest.performed_on BETWEEN p.starts_on AND p.ends_on) AS performed_inspections');
            }
            $rows = $query->orderByRaw('p.'.$dateColumn.' DESC NULLS LAST')->orderBy('installation_maintenance_records.id')->paginate($data['per_page'] ?? 50);

            app(ImportedRecordOrigins::class)->attach($rows->getCollection(), 'maintenance');

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
