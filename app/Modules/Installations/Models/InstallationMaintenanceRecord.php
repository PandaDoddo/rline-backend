<?php

namespace App\Modules\Installations\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstallationMaintenanceRecord extends TenantModel
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['*'];

    protected $with = ['dataProjection'];

    protected $hidden = ['dataProjection', 'reason', 'verified_by', 'verified_at', 'verification_signature'];

    protected $appends = ['values', 'check'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $attributes = ['version' => 1];

    public function dataProjection(): HasOne
    {
        return $this->hasOne(InstallationMaintenanceProjection::class, 'record_id');
    }

    public function getValuesAttribute(): array
    {
        return $this->dataProjection?->values ?? [];
    }

    public function getCheckAttribute(): ?array
    {
        return $this->dataProjection?->check;
    }

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
