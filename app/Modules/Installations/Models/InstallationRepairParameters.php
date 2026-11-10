<?php

namespace App\Modules\Installations\Models;

use App\Models\TenantModel;

class InstallationRepairParameters extends TenantModel
{
    protected $table = 'installation_repair_parameters';

    protected $primaryKey = 'record_id';

    protected $keyType = 'string';

    protected $fillable = ['record_id', 'starts_on', 'ends_on', 'repair_kind'];

    protected $dateFormat = 'Y-m-d';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }
}
