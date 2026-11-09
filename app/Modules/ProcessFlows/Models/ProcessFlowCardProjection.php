<?php

namespace App\Modules\ProcessFlows\Models;

use App\Models\TenantModel;

/** Read-only DTO projection over relational properties/annotations and a referenced immutable calculation. */
class ProcessFlowCardProjection extends TenantModel
{
    protected $table = 'process_flow_cards';

    protected $primaryKey = 'flow_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'sources' => 'array', 'checks' => 'array', 'accepted_calculation' => 'array'];
    }
}
