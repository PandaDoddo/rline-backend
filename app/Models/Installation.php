<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Installation extends TenantModel
{
    use HasUuids;

    protected $fillable = ['name', 'code', 'parent_id', 'passport'];

    protected $attributes = ['passport' => '{}'];

    protected function casts(): array
    {
        return ['passport' => 'array', 'cost_values' => 'array', 'cost_sources' => 'array', 'cost_checks' => 'array', 'no_stops' => 'boolean', 'flow_setup' => 'array', 'flow_structure_version' => 'integer', 'version' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FacilityNode::class, 'parent_id');
    }
}
