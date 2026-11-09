<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use App\Modules\Diagnostics\Models\EquipmentInspection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThicknessStage extends TenantModel
{
    use HasUuids;

    protected $table = 'equipment_thickness_stages';

    protected $fillable = ['measured_on'];

    protected function casts(): array
    {
        return ['measured_on' => 'date:Y-m-d', 'verified_at' => 'datetime'];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(EquipmentInspection::class, 'inspection_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(ThicknessMeasurement::class, 'stage_id');
    }
}
