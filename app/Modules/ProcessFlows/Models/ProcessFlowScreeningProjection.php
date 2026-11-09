<?php

namespace App\Modules\ProcessFlows\Models;

use App\Models\TenantModel;

/** SQL read projection, not stored JSON. */
class ProcessFlowScreeningProjection extends TenantModel
{
    protected $table = 'process_flow_screening_cards';

    protected $primaryKey = 'flow_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['state' => 'array'];
    }
}
