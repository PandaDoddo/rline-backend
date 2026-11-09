<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThicknessMeasurement extends TenantModel
{
    protected $table = 'equipment_thickness_values';

    protected $guarded = ['id', 'equipment_id', 'stage_id', 'cml_id'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ThicknessStage::class, 'stage_id');
    }

    public function cml(): BelongsTo
    {
        return $this->belongsTo(Cml::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(ThicknessPoint::class, 'measurement_id')->orderBy('point_number');
    }
}
