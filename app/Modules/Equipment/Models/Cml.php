<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cml extends TenantModel
{
    use HasUuids;

    protected $table = 'equipment_cmls';

    protected $fillable = ['point_count', 'notes'];

    protected function casts(): array
    {
        return ['point_count' => 'integer'];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(ThicknessMeasurement::class);
    }
}
