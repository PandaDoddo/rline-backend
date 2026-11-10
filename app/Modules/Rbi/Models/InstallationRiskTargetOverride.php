<?php

namespace App\Modules\Rbi\Models;

use App\Models\TenantModel;

class InstallationRiskTargetOverride extends TenantModel
{
    protected $table = 'installation_risk_target_overrides';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];
}
