<?php

namespace App\Modules\ProcessFlows\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ordered numeric bounds and attention zones; no JSON array storage. */
class ProcessFlowScreeningThreshold extends TenantModel
{
    protected $table = 'process_flow_screening_thresholds';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['bound_count' => 'integer', 'near_count' => 'integer', 'bound_1' => 'float', 'bound_2' => 'float', 'near_1' => 'float', 'near_2' => 'float'];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProcessFlowEnvironment::class, 'flow_id', 'flow_id');
    }
}
