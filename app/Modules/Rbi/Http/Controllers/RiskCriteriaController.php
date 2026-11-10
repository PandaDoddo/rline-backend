<?php

namespace App\Modules\Rbi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Application\InstallationImportData;
use App\Modules\Installations\Models\Installation;
use App\Modules\Rbi\Application\InstallationRiskCriteria;
use App\Modules\Rbi\Http\Requests\UpdateRiskCriteriaRequest;
use App\Modules\Rbi\Http\Resources\RiskCriteriaResource;
use App\Modules\Rbi\Http\Resources\RiskCriteriaSchemaResource;
use App\Modules\Shared\Application\AuditEvents;
use App\Modules\Shared\Domain\ValueEquality;
use App\Modules\Shared\Http\Resources\AuditEventResource;
use App\Modules\Shared\Infrastructure\TenantWrites;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;

class RiskCriteriaController extends Controller
{
    public function schema(InstallationRiskCriteria $criteria)
    {
        return new RiskCriteriaSchemaResource(new Fluent($criteria->schema()));
    }

    #[Response(status: 200, type: RiskCriteriaResource::class)]
    public function show(string $installation, InstallationRiskCriteria $criteria)
    {
        return DB::connection('tenant')->transaction(function () use ($installation, $criteria) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $model = Installation::findOrFail($installation);

            return $this->resourceResponse($model, $criteria->read($model));
        });
    }

    #[Response(status: 200, type: RiskCriteriaResource::class)]
    public function update(string $installation, UpdateRiskCriteriaRequest $request, InstallationRiskCriteria $criteria, TenantWrites $writes)
    {
        return $writes->transaction(function () use ($installation, $request, $criteria) {
            $data = $criteria->update($installation, $request->validated(), $request->user()->id);

            return $this->resourceResponse(Installation::findOrFail($installation), $data);
        });
    }

    private function resourceResponse(Installation $installation, array $data)
    {
        return (new RiskCriteriaResource(new Fluent($data + app(InstallationImportData::class)->describe($installation))))->response();
    }

    public function events(string $installation, InstallationRiskCriteria $criteria, AuditEvents $events)
    {
        Installation::findOrFail($installation);
        $page = $events->page('installation_risk_criteria', $installation, null);
        foreach ($page->getCollection() as $event) {
            $changes = [];
            foreach ($criteria->schema()['fields'] as $field) {
                $key = $field['key'];
                $before = $event->before['overrides'] ?? [];
                $after = $event->after['overrides'] ?? [];
                if (array_key_exists($key, $before) === array_key_exists($key, $after) && ValueEquality::same($before[$key] ?? null, $after[$key] ?? null)) {
                    continue;
                }
                $display = fn ($values) => ! array_key_exists($key, $values) ? 'по правилам / МРИ' : ($values[$key] === null ? 'отключено' : (string) $values[$key].' '.$field['unit']);
                $changes[] = ['key' => $key, 'label' => $field['label'], 'from' => $display($before), 'to' => $display($after)];
            }
            $event->changes = $changes;
        }

        return AuditEventResource::collection($page);
    }
}
