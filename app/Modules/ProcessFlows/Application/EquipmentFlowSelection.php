<?php

namespace App\Modules\ProcessFlows\Application;

use App\Modules\Equipment\Models\Equipment;
use App\Modules\ProcessFlows\Models\ProcessFlowNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Selection defines the equipment's available flows; physical exposure is stored separately. */
final class EquipmentFlowSelection
{
    public static function rules(): array
    {
        return ['flow_selection' => 'sometimes|array|list|max:5000', 'flow_selection.*' => 'required|uuid|distinct:strict'];
    }

    public function apply(Equipment $equipment, ?array $selection, int $actor): void
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new \LogicException('Equipment flow selection requires an aggregate transaction.');
        }
        $old = $db->table('equipment_flow_selections')->where('equipment_id', $equipment->id)->orderBy('position')->get()->keyBy('flow_id');
        $assigned = $db->table('equipment_flow_links as l')->join('equipment_flow_contacts as c', 'c.id', '=', 'l.contact_id')->where('c.equipment_id', $equipment->id)->distinct()->orderBy('l.flow_id')->pluck('l.flow_id')->all();
        $retained = $old->filter(fn ($row) => $row->installation_id === $equipment->installation_id)->keys()->all();
        $ids = $selection === null ? array_values(array_unique([...$retained, ...$assigned])) : array_map('strtolower', $selection);
        if (count($ids) !== count(array_unique($ids)) || count($ids) > 5000) {
            throw ValidationException::withMessages(['flow_selection' => 'Выберите не более 5000 разных потоков.']);
        }
        if (array_diff($assigned, $ids)) {
            throw ValidationException::withMessages(['flow_selection' => 'Сначала снимите назначения исключаемого потока в этой же правке.']);
        }
        $flows = ProcessFlowNode::without(['cardProjection', 'screeningProjection'])->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $rows = [];
        foreach ($ids as $position => $id) {
            $flow = $flows->get($id);
            if (! $flow || $flow->installation_id !== $equipment->installation_id || $flow->kind !== 'flow') {
                throw ValidationException::withMessages(['flow_selection' => 'Выберите конечный поток установки оборудования.']);
            }
            if ($flow->status !== 'active' && ! $old->has($id)) {
                throw ValidationException::withMessages(['flow_selection' => 'Архивный поток нельзя выбрать заново.']);
            }
            $rows[] = ['equipment_id' => $equipment->id, 'flow_id' => $id, 'installation_id' => $equipment->installation_id, 'position' => $position, 'created_by' => $old->get($id)?->created_by ?? $actor, 'created_at' => $old->get($id)?->created_at ?? now(), 'updated_at' => now()];
        }
        $db->table('equipment_flow_selections')->where('equipment_id', $equipment->id)->whereNotIn('flow_id', $ids)->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table('equipment_flow_selections')->upsert($chunk, ['equipment_id', 'flow_id'], ['installation_id', 'position', 'updated_at']);
        }
    }

    public function read(Equipment $equipment): array
    {
        return DB::connection('tenant')->table('equipment_flow_selections as s')->join('process_flow_nodes as f', 'f.id', '=', 's.flow_id')->where('s.equipment_id', $equipment->id)->orderBy('s.position')
            ->get(['f.id as flow_id', 'f.name as flow_name', 'f.code as flow_code', 'f.category as flow_category', 'f.medium_kind', 'f.status as flow_status'])->map(fn ($row) => (array) $row)->all();
    }
}
