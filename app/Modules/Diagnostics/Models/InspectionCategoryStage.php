<?php

namespace App\Modules\Diagnostics\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectionCategoryStage extends TenantModel
{
    use HasUuids;

    protected $table = 'equipment_inspection_category_stages';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['reference_date' => 'immutable_date'];
    }

    public function observations(): HasMany
    {
        return $this->hasMany(InspectionCategoryObservation::class, 'stage_id')->orderBy('component_id');
    }
}
