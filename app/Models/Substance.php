<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Substance extends TenantModel
{
    use HasUuids;

    protected $fillable = ['label', 'overrides', 'status'];

    protected $attributes = ['base_values' => '{}', 'base_raw' => '{}', 'base_flags' => '{}', 'overrides' => '{}', 'sources' => '{}', 'checks' => '{}', 'status' => 'active', 'version' => 1, 'origin' => 'custom'];

    protected function casts(): array
    {
        return ['base_values' => 'array', 'base_raw' => 'array', 'base_flags' => 'array', 'overrides' => 'array', 'sources' => 'array', 'checks' => 'array', 'version' => 'integer'];
    }

    public function effective(): array
    {
        return array_replace($this->base_values ?? [], $this->overrides ?? []);
    }

    public function needsReview(): bool
    {
        if (! $this->base_flags) {
            return false;
        }
        $keys = array_keys($this->base_flags['fields'] ?? []);
        if (! $keys) {
            return true;
        }
        foreach ($keys as $key) {
            if (! isset($this->checks[$key])) {
                return true;
            }
        }

        return false;
    }
}
