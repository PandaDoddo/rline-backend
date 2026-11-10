<?php

namespace App\Modules\Installations\Models;

use App\Models\TenantModel;

class InstallationMaintenanceProjection extends TenantModel
{
    protected $table = 'installation_maintenance_cards';

    protected $primaryKey = 'record_id';

    protected $keyType = 'string';

    protected $guarded = ['*'];

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['values' => 'array', 'check' => 'array'];
    }
}
