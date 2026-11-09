<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use App\Modules\Diagnostics\Models\EquipmentInspection;
use App\Modules\Installations\Models\Installation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Equipment extends TenantModel
{
    use HasUuids;

    protected $table = 'equipment';

    protected $fillable = ['installation_id', 'profile_code', 'status'];

    protected $attributes = ['status' => 'active', 'version' => 1];

    protected $with = ['passportProjection'];

    protected $hidden = ['passportProjection'];

    protected $appends = ['passport', 'passport_qualifiers', 'checks'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'next_component_number' => 'integer'];
    }

    public function passportProjection(): HasOne
    {
        return $this->hasOne(EquipmentPassportProjection::class);
    }

    public function classification(): HasOne
    {
        return $this->hasOne(EquipmentClassification::class);
    }

    public function operatingParameters(): HasOne
    {
        return $this->hasOne(EquipmentOperatingParameters::class);
    }

    public function construction(): HasOne
    {
        return $this->hasOne(EquipmentConstruction::class);
    }

    public function protection(): HasOne
    {
        return $this->hasOne(EquipmentProtection::class);
    }

    public function medium(): HasOne
    {
        return $this->hasOne(EquipmentMedium::class);
    }

    public function corrosionSettings(): HasOne
    {
        return $this->hasOne(EquipmentCorrosionSettings::class);
    }

    public function diagnosticSettings(): HasOne
    {
        return $this->hasOne(EquipmentDiagnosticSettings::class);
    }

    public function getPassportAttribute(): array
    {
        return $this->passportProjection?->passport ?? [];
    }

    public function getPassportQualifiersAttribute(): array
    {
        return $this->passportProjection?->qualifiers ?? [];
    }

    public function getChecksAttribute(): array
    {
        return $this->passportProjection?->checks ?? [];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(EquipmentComponent::class)->orderBy('position')->orderBy('code');
    }

    public function operatingScopes(): HasMany
    {
        return $this->hasMany(EquipmentOperatingScope::class)->orderBy('kind')->orderBy('component_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(EquipmentInspection::class)->orderByDesc('is_primary')->orderBy('performed_on')->orderBy('id');
    }
}
