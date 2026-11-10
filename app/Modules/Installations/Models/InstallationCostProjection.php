<?php

namespace App\Modules\Installations\Models;

use App\Models\TenantModel;

class InstallationCostProjection extends TenantModel
{
    protected $table = 'installation_cost_cards';

    protected $primaryKey = 'installation_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['values' => 'array', 'sources' => 'array', 'checks' => 'array'];
    }
}
