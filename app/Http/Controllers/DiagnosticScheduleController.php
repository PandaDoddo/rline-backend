<?php

namespace App\Http\Controllers;

use App\Domain\EquipmentReadQuery;
use App\Http\Requests\DiagnosticScheduleRequest;
use App\Http\Resources\DiagnosticScheduleResource;
use App\Models\EquipmentInspection;
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
            $planned = fn ($query) => $query->whereNull('performed_on')->whereNotNull('planned_on')->when(isset($data['kind']), fn ($query) => $query->whereIn('kind', $data['kind'] === 'ГИ/ПИ' ? ['ГИ', 'ПИ'] : [$data['kind']]));
            if (($data['plan_state'] ?? 'all') === 'missing') {
                $query->whereDoesntHave('inspections', $planned);
            }
            if (($data['plan_state'] ?? '') === 'overdue') {
                $query->whereHas('inspections', fn ($query) => $planned($query)->where('planned_on', '<', $today));
            }
            if (($data['plan_state'] ?? '') === 'upcoming') {
                $query->whereHas('inspections', fn ($query) => $planned($query)->whereBetween('planned_on', [$today, $deadline]));
            }
            if (($data['sort'] ?? '') === 'next_on') {
                $next = EquipmentInspection::query()->selectRaw('MIN(planned_on)')->whereColumn('equipment_id', 'equipment.id');
                $planned($next);
                $query->orderByRaw('('.$next->toSql().') '.($data['direction'] ?? 'asc').' NULLS LAST', $next->getBindings())->orderBy('id');
            }
            $page = $query->paginate($data['per_page'] ?? 50)->withQueryString();
            $summary = DB::connection('tenant')->table('equipment_inspections')->whereIn('equipment_id', $page->getCollection()->pluck('id'))
                ->selectRaw('equipment_id, kind, MAX(performed_on) AS performed_on, MIN(CASE WHEN performed_on IS NULL THEN planned_on END) AS planned_on')
                ->groupBy('equipment_id', 'kind')->get()->groupBy('equipment_id');
            foreach ($page->getCollection() as $equipment) {
                $equipment->setAttribute('schedule_diagnostics', collect($summary->get($equipment->id, []))->map(fn ($row) => ['kind' => $row->kind, 'performed_on' => $row->performed_on, 'planned_on' => $row->planned_on])->all());
            }

            return DiagnosticScheduleResource::collection($page)->additional(['as_of' => $today])->response();
        });
    }
}
