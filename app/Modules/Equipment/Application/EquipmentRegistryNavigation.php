<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Installations\Models\FacilityNode;
use App\Modules\Installations\Models\Installation;

final class EquipmentRegistryNavigation
{
    public function read(array $filters): array
    {
        $counts = app(EquipmentReadQuery::class)->query($filters, false)->withoutEagerLoads()
            ->selectRaw('installation_id, profile_code, count(*) as equipment_count')->groupBy('installation_id', 'profile_code')->get();
        $groups = [];
        $byInstallation = [];
        foreach ($counts as $row) {
            $group = config('equipment.profiles')[$row->profile_code]['g'];
            $groups[$group] = ($groups[$group] ?? 0) + (int) $row->equipment_count;
            $byInstallation[$row->installation_id] = ($byInstallation[$row->installation_id] ?? 0) + (int) $row->equipment_count;
        }
        $locations = [];
        foreach (FacilityNode::orderBy('name')->get(['id', 'parent_id', 'kind', 'name', 'status']) as $row) {
            $locations[$row->id] = ['id' => $row->id, 'parent_id' => $row->parent_id, 'kind' => $row->kind, 'name' => $row->name, 'status' => $row->status, 'count' => 0];
        }
        foreach (Installation::withoutEagerLoads()->orderBy('name')->get(['id', 'parent_id', 'name', 'status']) as $row) {
            $count = $byInstallation[$row->id] ?? 0;
            $locations[$row->id] = ['id' => $row->id, 'parent_id' => $row->parent_id, 'kind' => 'installation', 'name' => $row->name, 'status' => $row->status, 'count' => $count];
            $parent = $row->parent_id;
            while ($parent !== null && isset($locations[$parent])) {
                $locations[$parent]['count'] += $count;
                $parent = $locations[$parent]['parent_id'];
            }
        }

        return ['total' => array_sum($groups), 'groups' => array_map(fn ($code, $count) => ['code' => $code, 'count' => $count], array_keys($groups), array_values($groups)), 'locations' => array_values($locations)];
    }
}
