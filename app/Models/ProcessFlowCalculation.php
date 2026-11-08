<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ProcessFlowCalculation extends TenantModel
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['inputs' => 'array', 'result' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
