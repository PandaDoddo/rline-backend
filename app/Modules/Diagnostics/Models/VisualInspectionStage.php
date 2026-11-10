<?php

namespace App\Modules\Diagnostics\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisualInspectionStage extends TenantModel
{
    use HasUuids;

    protected $table = 'equipment_visual_stages';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['reference_date' => 'immutable_date', 'measured_on' => 'immutable_date', 'verified_at' => 'immutable_datetime', 'verified_by' => 'integer'];
    }

    public function observations(): HasMany
    {
        return $this->hasMany(VisualInspectionObservation::class, 'stage_id')->orderBy('component_id');
    }
}
