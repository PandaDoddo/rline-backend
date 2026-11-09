<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use App\Modules\Equipment\Domain\OperatingScopeSchema;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentOperatingScope extends TenantModel
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        $casts = ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
        foreach (OperatingScopeSchema::FIELDS as $key => $kinds) {
            if (! in_array($key, ['relief_valve_present', 'medium', 'alternative_medium'], true)) {
                $casts[$key] = 'float';
            }
        }

        return $casts;
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(EquipmentOperatingScopeAnnotation::class, 'scope_id');
    }
}
