<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Composite (scope_id,field_key); aggregate command owns writes. */
class EquipmentOperatingScopeAnnotation extends TenantModel
{
    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['value_present' => 'boolean', 'verified_at' => 'immutable_datetime'];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(EquipmentOperatingScope::class, 'scope_id');
    }
}
