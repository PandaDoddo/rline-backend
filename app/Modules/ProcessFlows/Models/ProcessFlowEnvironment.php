<?php

namespace App\Modules\ProcessFlows\Models;

use App\Models\TenantModel;
use App\Modules\ProcessFlows\Domain\FlowEnvironmentSchema;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessFlowEnvironment extends TenantModel
{
    protected $table = 'process_flow_environments';

    protected $primaryKey = 'flow_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        $casts = ['updated_at' => 'immutable_datetime'];
        foreach (FlowEnvironmentSchema::COLUMNS as [$column,$type]) {
            $casts[$column] = $type === 'boolean' ? 'boolean' : 'float';
        }

        return $casts;
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ProcessFlowNode::class, 'flow_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProcessFlowScreeningComment::class, 'flow_id', 'flow_id');
    }

    public function thresholds(): HasMany
    {
        return $this->hasMany(ProcessFlowScreeningThreshold::class, 'flow_id', 'flow_id');
    }
}
