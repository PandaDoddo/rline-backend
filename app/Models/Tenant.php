<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['auth_config', 'database_config'];

    protected function casts(): array
    {
        return ['auth_config' => 'encrypted:array', 'database_config' => 'encrypted:array'];
    }
}
