<?php

namespace App\Domain;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Builder;

class EquipmentReadQuery
{
    public function query(array $data, bool $sort = true): Builder
    {
        $query = Equipment::with('installation.parent.parent.parent')->where('status', $data['status'] ?? 'active');
        if (isset($data['installation_id'])) {
            $query->where('installation_id', $data['installation_id']);
        }
        if (isset($data['group'])) {
            $profiles = array_keys(array_filter(config('equipment.profiles'), fn ($profile) => $profile['g'] === $data['group']));
            $query->whereIn('profile_code', $profiles);
        }
        if (($q = trim($data['q'] ?? '')) !== '') {
            $pattern = '%'.addcslashes($q, '\\%_').'%';
            $query->where(function ($query) use ($pattern) {
                foreach (['name', 'tag', 'serial_number', 'registration_number'] as $field) {
                    $query->orWhereRaw("passport->>'".$field."' ILIKE ?", [$pattern]);
                }
                $query->orWhereHas('installation', fn ($installation) => $installation->whereRaw('name ILIKE ?', [$pattern]));
            });
        }
        if ($sort) {
            $field = $data['sort'] ?? 'name';
            $direction = $data['direction'] ?? 'asc';
            $query->orderByRaw(($field === 'updated_at' ? 'updated_at' : "passport->>'".$field."'").' '.$direction.' NULLS LAST')->orderBy('id');
        }

        return $query;
    }
}
