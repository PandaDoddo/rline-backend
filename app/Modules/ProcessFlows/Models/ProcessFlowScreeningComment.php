<?php

namespace App\Modules\ProcessFlows\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Composite (flow_id,item_id); only the aggregate repository writes these rows. */
class ProcessFlowScreeningComment extends TenantModel
{
    protected $table = 'process_flow_screening_comments';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProcessFlowEnvironment::class, 'flow_id', 'flow_id');
    }
}
