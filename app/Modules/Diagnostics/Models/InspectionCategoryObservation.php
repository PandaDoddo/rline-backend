<?php

namespace App\Modules\Diagnostics\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InspectionCategoryObservation extends TenantModel
{
    use HasUuids;

    protected $table = 'equipment_inspection_category_observations';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['cracks_present' => 'boolean'];
    }
}
