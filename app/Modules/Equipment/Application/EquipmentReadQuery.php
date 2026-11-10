<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Domain\EquipmentIdentity;
use App\Modules\Equipment\Models\Equipment;
use Illuminate\Database\Eloquent\Builder;

class EquipmentReadQuery
{
    public function query(array $data, bool $sort = true): Builder
    {
        $query = Equipment::with('installation.parent.parent.parent')->where('status', $data['status'] ?? 'active');
        if (isset($data['installation_id'])) {
            $query->where('installation_id', $data['installation_id']);
        }
        if (isset($data['facility_node_id'])) {
            $id = $data['facility_node_id'];
            $query->whereHas('installation', fn ($installation) => $installation->where(fn ($ancestors) => $ancestors
                ->where('parent_id', $id)->orWhereHas('parent', fn ($parent) => $parent->where('parent_id', $id))
                ->orWhereHas('parent.parent', fn ($parent) => $parent->where('parent_id', $id))));
        }
        if (isset($data['group'])) {
            $groups = explode(',', $data['group']);
            $profiles = array_keys(array_filter(config('equipment.profiles'), fn ($profile) => in_array($profile['g'], $groups, true)));
            $query->whereIn('profile_code', $profiles);
        }
        if (($q = trim($data['q'] ?? '')) !== '') {
            $pattern = '%'.addcslashes($q, '\\%_').'%';
            $query->where(function ($query) use ($pattern) {
                foreach (['name', 'serial_number', 'registration_number'] as $field) {
                    $query->orWhereRaw('"'.$field.'" ILIKE ?', [$pattern]);
                }
                $query->orWhereRaw(EquipmentIdentity::POSITION_SQL.' ILIKE ?', [$pattern]);
                $query->orWhereHas('installation', fn ($installation) => $installation->whereRaw('name ILIKE ?', [$pattern]));
            });
        }
        if ($sort) {
            $field = $data['sort'] ?? 'name';
            $direction = $data['direction'] ?? 'asc';
            $query->orderByRaw(($field === 'tag' ? EquipmentIdentity::POSITION_SQL : '"'.$field.'"').' '.$direction.' NULLS LAST')->orderBy('id');
        }

        return $query;
    }
}
