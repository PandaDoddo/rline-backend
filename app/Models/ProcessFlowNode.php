<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessFlowNode extends TenantModel
{
    use HasUuids;

    protected $fillable = ['name', 'attributes', 'basis', 'medium_kind', 'status'];

    protected $attributes = ['attributes' => '{}', 'sources' => '{}', 'checks' => '{}', 'basis' => 'mass', 'medium_kind' => 'fluid', 'status' => 'active', 'version' => 1];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'sources' => 'array', 'checks' => 'array', 'accepted_calculation' => 'array', 'version' => 'integer'];
    }

    public function composition(): HasMany
    {
        return $this->hasMany(ProcessFlowComposition::class, 'flow_id')->orderBy('position')->orderBy('id');
    }
}
