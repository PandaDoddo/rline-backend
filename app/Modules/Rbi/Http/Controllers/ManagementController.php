<?php

namespace App\Modules\Rbi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Application\InstallationImportData;
use App\Modules\Installations\Models\Installation;
use App\Modules\Rbi\Application\InstallationManagement;
use App\Modules\Rbi\Domain\ManagementHistory;
use App\Modules\Rbi\Http\Requests\UpdateManagementRequest;
use App\Modules\Rbi\Http\Resources\ManagementResource;
use App\Modules\Rbi\Http\Resources\ManagementSchemaResource;
use App\Modules\Shared\Application\AuditEvents;
use App\Modules\Shared\Http\Resources\AuditEventResource;
use App\Modules\Shared\Infrastructure\TenantWrites;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;

class ManagementController extends Controller
{
    public function schema(InstallationManagement $management)
    {
        $schema = $management->schema();

        return new ManagementSchemaResource(new Fluent(['version' => $schema['version'], 'sections' => $schema['sections']]));
    }

    #[Response(status: 200, type: ManagementResource::class)]
    public function show(string $installation, InstallationManagement $management)
    {
        return DB::connection('tenant')->transaction(function () use ($installation, $management) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $model = Installation::findOrFail($installation);

            return $this->resourceResponse($model, $management->read($model));
        });
    }

    #[Response(status: 200, type: ManagementResource::class)]
    public function update(UpdateManagementRequest $request, string $installation, InstallationManagement $management, TenantWrites $writes)
    {
        return DB::connection('tenant')->transaction(function () use ($request, $installation, $management, $writes) {
            $data = $management->update($installation, $request->validated(), $request->user()->id, $writes);

            return $this->resourceResponse(Installation::findOrFail($installation), $data);
        });
    }

    private function resourceResponse(Installation $installation, array $data)
    {
        return (new ManagementResource(new Fluent($data + app(InstallationImportData::class)->describe($installation, true))))->response();
    }

    public function events(string $installation, InstallationManagement $management, AuditEvents $events, ManagementHistory $history)
    {
        Installation::findOrFail($installation);
        $page = $events->page('installation_management', $installation, null);
        foreach ($page->getCollection() as $event) {
            $event->changes = $history->changes($event->before ?? [], $event->after ?? [], $management->schema()['sections']);
        }

        return AuditEventResource::collection($page);
    }
}
