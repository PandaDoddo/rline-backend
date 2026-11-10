<?php

namespace App\Modules\Installations\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Installation extends TenantModel
{
    use HasUuids;

    protected $fillable = ['name', 'code', 'parent_id'];

    protected $attributes = ['status' => 'active'];

    protected $with = ['technicalPassport', 'costProjection'];

    protected $hidden = ['technicalPassport', 'costProjection'];

    protected $appends = ['passport', 'cost_values', 'cost_sources', 'cost_checks'];

    public function costProjection(): HasOne
    {
        return $this->hasOne(InstallationCostProjection::class, 'installation_id');
    }

    public function getCostValuesAttribute(): array
    {
        return $this->costProjection?->values ?? [];
    }

    public function getCostSourcesAttribute(): array
    {
        return $this->costProjection?->sources ?? [];
    }

    public function getCostChecksAttribute(): array
    {
        return $this->costProjection?->checks ?? [];
    }

    public function technicalPassport(): HasOne
    {
        return $this->hasOne(InstallationPassport::class, 'installation_id');
    }

    public function getPassportAttribute(): array
    {
        return $this->getRelationValue('technicalPassport')?->publicValues() ?? [];
    }

    protected function casts(): array
    {
        return ['archive_manifest' => 'array', 'no_stops' => 'boolean', 'flow_setup' => 'array', 'flow_structure_version' => 'integer', 'version' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FacilityNode::class, 'parent_id');
    }
}
