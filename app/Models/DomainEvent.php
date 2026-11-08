<?php

namespace App\Models;

class DomainEvent extends TenantModel
{
    protected $table = 'domain_events';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'actor_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
