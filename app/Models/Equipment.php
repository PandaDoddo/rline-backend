<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends TenantModel
{
    use HasUuids;

    protected $table = 'equipment';

    protected $fillable = ['installation_id', 'profile_code', 'passport', 'status'];

    protected $attributes = ['checks' => '{}', 'status' => 'active', 'version' => 1];

    protected function casts(): array
    {
        return ['passport' => 'array', 'checks' => 'array', 'version' => 'integer', 'next_component_number' => 'integer'];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(EquipmentComponent::class)->orderBy('position')->orderBy('code');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(EquipmentInspection::class)->orderByDesc('is_primary')->orderBy('performed_on')->orderBy('id');
    }
}
