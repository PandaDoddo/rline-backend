<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SteelGrade extends TenantModel
{
    use HasUuids;

    protected $fillable = ['label', 'overrides', 'status'];

    protected $attributes = ['base_values' => '{}', 'base_raw' => '{}', 'overrides' => '{}', 'sources' => '{}', 'checks' => '{}', 'status' => 'active', 'version' => 1, 'origin' => 'custom'];

    protected function casts(): array
    {
        return ['base_values' => 'array', 'base_raw' => 'array', 'overrides' => 'array', 'sources' => 'array', 'checks' => 'array', 'version' => 'integer'];
    }

    public function effective(): array
    {
        return array_replace($this->base_values ?? [], $this->overrides ?? []);
    }
}
