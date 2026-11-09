<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThicknessPoint extends TenantModel
{
    protected $table = 'equipment_thickness_points';

    public $timestamps = false;

    protected $fillable = ['thickness_mm'];

    protected function casts(): array
    {
        return ['point_number' => 'integer', 'thickness_mm' => 'float'];
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(ThicknessMeasurement::class, 'measurement_id');
    }
}
