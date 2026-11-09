<?php

namespace App\Modules\ProcessFlows\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessFlowProperties extends TenantModel
{
    protected $table = 'process_flow_properties';

    protected $primaryKey = 'flow_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['temperature' => 'float', 'pressure' => 'float', 'flow_rate_t_h' => 'float', 'velocity_m_s' => 'float', 'ph' => 'float', 'density' => 'float', 'molecular_weight' => 'float', 'boiling_point' => 'float'];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ProcessFlowNode::class, 'flow_id');
    }
}
