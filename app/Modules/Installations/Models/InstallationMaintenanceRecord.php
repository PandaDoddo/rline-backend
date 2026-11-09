<?php

namespace App\Modules\Installations\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstallationMaintenanceRecord extends TenantModel
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['values'];

    protected function casts(): array
    {
        return ['values' => 'array', 'check' => 'array'];
    }
}
