<?php

namespace App\Modules\Installations\Models;

use App\Models\TenantModel;

class InstallationMriParameters extends TenantModel
{
    protected $table = 'installation_mri_parameters';

    protected $primaryKey = 'record_id';

    protected $keyType = 'string';

    protected $fillable = ['record_id', 'effective_date', 'interval_months'];

    protected $dateFormat = 'Y-m-d';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['effective_date' => 'immutable_date', 'interval_months' => 'integer', 'active_slot' => 'boolean'];
    }
}
