<?php

namespace App\Modules\ProcessFlows\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Composite key (flow_id,field_key); aggregate repository owns writes. */
class ProcessFlowPropertyAnnotation extends TenantModel
{
    protected $table = 'process_flow_property_annotations';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['value_present' => 'boolean', 'source_present' => 'boolean', 'source_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime'];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ProcessFlowNode::class, 'flow_id');
    }
}
