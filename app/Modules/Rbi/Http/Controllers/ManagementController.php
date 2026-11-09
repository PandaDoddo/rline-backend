<?php

namespace App\Modules\Rbi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Installations\Models\Installation;
use App\Modules\Rbi\Application\InstallationManagement;
use App\Modules\Rbi\Domain\ManagementHistory;
use App\Modules\Rbi\Http\Requests\UpdateManagementRequest;
use App\Modules\Rbi\Http\Resources\ManagementResource;
use App\Modules\Rbi\Http\Resources\ManagementSchemaResource;
use App\Modules\Shared\Application\AuditEvents;
use App\Modules\Shared\Http\Resources\AuditEventResource;
use App\Modules\Shared\Infrastructure\TenantWrites;
use Illuminate\Support\Fluent;

class ManagementController extends Controller
{
    public function schema(InstallationManagement $management)
    {
        $schema = $management->schema();

        return new ManagementSchemaResource(new Fluent(['version' => $schema['version'], 'sections' => $schema['sections']]));
    }

    public function show(string $installation, InstallationManagement $management)
    {
        return new ManagementResource(new Fluent($management->read(Installation::findOrFail($installation))));
    }

    public function update(UpdateManagementRequest $request, string $installation, InstallationManagement $management, TenantWrites $writes)
    {
        return new ManagementResource(new Fluent($management->update($installation, $request->validated(), $request->user()->id, $writes)));
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
