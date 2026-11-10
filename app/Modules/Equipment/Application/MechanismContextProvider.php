<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Domain\Screening\MechanismContextFactory;
use App\Modules\Equipment\Models\Equipment;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Batch only the fields needed by screening; no per-element catalog or stream queries. */
final class MechanismContextProvider
{
    public function factory(Equipment $equipment, array $flows, array $scopes, array $visual, array $facts, bool $lock = false): MechanismContextFactory
    {
        $db = DB::connection('tenant');
        if ($lock && $db->transactionLevel() === 0) {
            throw new LogicException('Source locks require an equipment aggregate transaction.');
        }
        $equipment->loadMissing('components');
        $flowIds = [];
        foreach ($flows['contacts'] ?? [] as $contact) {
            foreach ($contact['flows'] as $link) {
                $flowIds[$link['flow_id']] = true;
            }
        }
        $cards = [];
        if ($flowIds) {
            $query = $db->table('process_flow_nodes as f')->leftJoin('process_flow_screening_cards as s', 's.flow_id', '=', 'f.id')->leftJoin('process_flow_properties as p', 'p.flow_id', '=', 'f.id')
                ->whereIn('f.id', array_keys($flowIds))->where('f.installation_id', $equipment->installation_id)->where('f.kind', 'flow')->orderBy('f.id')
                ->select(['f.id', 'f.kind', 'f.category', 'f.medium_kind', 'f.status', 'f.version', 's.state', 'p.ph', 'p.temperature', 'p.pressure']);
            if ($lock) {
                $query->lock('for share of f');
            }
            foreach ($query->get() as $row) {
                $screening = $row->state === null ? null : json_decode($row->state, true, flags: JSON_THROW_ON_ERROR);
                if ($screening === null && $row->ph !== null) {
                    $screening = ['values' => ['ph' => (float) $row->ph], 'comments' => [], 'thresholds' => []];
                }
                $cards[$row->id] = ['id' => $row->id, 'category' => $row->category, 'medium_kind' => $row->medium_kind, 'status' => $row->status, 'version' => (int) $row->version, 'screening' => $screening,
                    'parameters' => ['temperature_max_c' => $row->temperature === null ? null : (float) $row->temperature, 'pressure_operating_mpa' => $row->pressure === null ? null : (float) $row->pressure]];
            }
        }
        $names = [];
        foreach ($equipment->components as $component) {
            if (is_string($component->material) && trim($component->material) !== '') {
                $names[trim($component->material)] = true;
            }
        }
        $materials = [];
        if ($names) {
            $properties = [];
            foreach (['kind', 'subtype', 'rbi_group', 'rbi_crCO2', 'rbi_cr885', 'matMin'] as $field) {
                $properties[] = "'".$field."',coalesce(overrides->'".$field."',base_values->'".$field."')";
            }
            $query = $db->table('steel_grades')->whereIn('label', array_keys($names))->orderBy('id')->select(['id', 'label', 'status', 'version'])->selectRaw('jsonb_build_object('.implode(',', $properties).') as screening_values');
            if ($lock) {
                $query->sharedLock();
            }
            $grouped = $query->get()->groupBy('label');
            foreach ($grouped as $name => $matches) {
                $active = $matches->where('status', 'active');
                $row = $active->count() === 1 ? $active->first() : ($matches->count() === 1 ? $matches->first() : null);
                if ($row) {
                    $materials[$name] = ['id' => $row->id, 'status' => $row->status, 'version' => (int) $row->version, 'values' => json_decode($row->screening_values, true, flags: JSON_THROW_ON_ERROR)];
                }
            }
        }

        return new MechanismContextFactory($equipment->components->toArray(), $equipment->passport, $scopes, $flows, $cards, $materials, $facts['inputs'], array_column($facts['component_inputs'], null, 'component_id'), $visual);
    }
}
