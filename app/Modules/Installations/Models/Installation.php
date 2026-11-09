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

    protected $with = ['technicalPassport'];

    protected $hidden = ['technicalPassport'];

    protected $appends = ['passport'];

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
        return ['archive_manifest' => 'array', 'cost_values' => 'array', 'cost_sources' => 'array', 'cost_checks' => 'array', 'no_stops' => 'boolean', 'flow_setup' => 'array', 'flow_structure_version' => 'integer', 'version' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FacilityNode::class, 'parent_id');
    }
}
