<?php

namespace App\Modules\Installations\Models;

use App\Models\TenantModel;

class InstallationStopParameters extends TenantModel
{
    protected $table = 'installation_stop_parameters';

    protected $primaryKey = 'record_id';

    protected $keyType = 'string';

    protected $fillable = ['record_id', 'starts_at', 'ends_at'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
