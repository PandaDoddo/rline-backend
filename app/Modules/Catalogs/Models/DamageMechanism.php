<?php

namespace App\Modules\Catalogs\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DamageMechanism extends TenantModel
{
    use HasUuids;

    protected $fillable = ['label', 'overrides', 'status'];

    protected $attributes = ['base_values' => '{}', 'base_raw' => '{}', 'overrides' => '{}', 'sources' => '{}', 'checks' => '{}', 'status' => 'active', 'version' => 1, 'origin' => 'base'];

    protected function casts(): array
    {
        return ['base_values' => 'array', 'base_raw' => 'array', 'overrides' => 'array', 'sources' => 'array', 'checks' => 'array', 'version' => 'integer', 'summary_material_groups' => 'array'];
    }

    public function effective(): array
    {
        return array_replace($this->base_values ?? [], $this->overrides ?? []);
    }
}
