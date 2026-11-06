<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantMembership extends Model
{
    protected $table = 'user_tenants';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
