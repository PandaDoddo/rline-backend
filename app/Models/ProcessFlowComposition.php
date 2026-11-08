<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessFlowComposition extends TenantModel
{
    use HasUuids;

    protected $table = 'process_flow_composition';

    protected $fillable = ['substance_id', 'role', 'name', 'amount', 'unit', 'plus', 'notes', 'position'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'plus' => 'boolean', 'position' => 'integer'];
    }

    public function substance(): BelongsTo
    {
        return $this->belongsTo(Substance::class);
    }
}
