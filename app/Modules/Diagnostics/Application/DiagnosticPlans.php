<?php

namespace App\Modules\Diagnostics\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DiagnosticPlans
{
    public function query(): Builder
    {
        return DB::connection('tenant')->table('equipment_diagnostic_plans');
    }

    public function read(array $equipmentIds): array
    {
        if (! $equipmentIds) {
            return [];
        }

        return $this->query()->whereIn('equipment_id', $equipmentIds)->orderBy('kind')->get()->groupBy('equipment_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => ['kind' => $row->kind, 'performed_on' => $row->performed_on, 'planned_on' => $row->planned_on,
                'absorbed_by' => $row->absorbed_by, 'absorbed_on' => $row->absorbed_on, 'performed_by' => $row->performed_by])->all())->all();
    }
}
