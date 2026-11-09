<?php

namespace App\Modules\Diagnostics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Diagnostics\Application\DiagnosticPlans;
use App\Modules\Diagnostics\Http\Requests\DiagnosticScheduleRequest;
use App\Modules\Diagnostics\Http\Resources\DiagnosticScheduleResource;
use App\Modules\Equipment\Application\EquipmentReadQuery;
use Illuminate\Support\Facades\DB;

class DiagnosticScheduleController extends Controller
{
    public function __invoke(DiagnosticScheduleRequest $request, EquipmentReadQuery $reader)
    {
        return DB::connection('tenant')->transaction(function () use ($request, $reader) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $data = $request->validated();
            $query = $reader->query($data, ($data['sort'] ?? '') !== 'next_on');
            $today = now()->toDateString();
            $deadline = now()->addDays((int) ($data['within_days'] ?? 90))->toDateString();
            $planned = fn ($query, $effective = true) => $query->when($effective, fn ($query) => $query->whereNull('absorbed_by'))->whereNotNull('planned_on')->when(isset($data['kind']), fn ($query) => $query->whereIn('kind', $data['kind'] === 'ГИ/ПИ' ? ['ГИ', 'ПИ'] : [$data['kind']]));
            $plans = fn () => app(DiagnosticPlans::class)->query()->selectRaw('1')->whereColumn('equipment_id', 'equipment.id');
            if (($data['plan_state'] ?? 'all') === 'missing') {
                $query->whereNotExists($planned($plans(), false));
            }
            if (($data['plan_state'] ?? '') === 'overdue') {
                $query->whereExists($planned($plans())->where('planned_on', '<', $today));
            }
            if (($data['plan_state'] ?? '') === 'upcoming') {
                $query->whereExists($planned($plans())->whereBetween('planned_on', [$today, $deadline]));
            }
            if (($data['sort'] ?? '') === 'next_on') {
                $next = app(DiagnosticPlans::class)->query()->selectRaw('MIN(planned_on)')->whereColumn('equipment_id', 'equipment.id');
                $planned($next);
                $query->orderByRaw('('.$next->toSql().') '.($data['direction'] ?? 'asc').' NULLS LAST', $next->getBindings())->orderBy('id');
            }
            $page = $query->paginate($data['per_page'] ?? 50)->withQueryString();
            $summary = app(DiagnosticPlans::class)->read($page->getCollection()->pluck('id')->all());
            foreach ($page->getCollection() as $equipment) {
                $equipment->setAttribute('schedule_diagnostics', $summary[$equipment->id] ?? []);
            }

            return DiagnosticScheduleResource::collection($page)->additional(['as_of' => $today])->response();
        });
    }
}
