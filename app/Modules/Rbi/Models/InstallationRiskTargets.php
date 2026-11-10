<?php

namespace App\Modules\Rbi\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallationRiskTargets extends TenantModel
{
    protected $table = 'installation_risk_targets';

    protected $primaryKey = 'installation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    public function overrides(): HasMany
    {
        return $this->hasMany(InstallationRiskTargetOverride::class, 'installation_id');
    }

    protected function casts(): array
    {
        return ['area' => 'float', 'fin' => 'float', 'pof' => 'float', 'dfmax' => 'float', 'dThin' => 'float', 'dScc' => 'float', 'dExt' => 'float', 'intvl' => 'float', 'planRec' => 'float', 'planMax' => 'float', 'planScc' => 'float', 'H' => 'float'];
    }
}
