<?php

namespace App\Modules\Diagnostics\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EquipmentInspection extends TenantModel
{
    use HasUuids;

    protected $fillable = ['kind', 'planned_on', 'performed_on', 'report_number', 'notes', 'next_on', 'organization', 'inspection_type', 'follow_up_dates', 'unknown_fields'];

    protected $attributes = ['is_primary' => false, 'follow_up_dates' => '{}', 'unknown_fields' => '[]'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'planned_on' => 'date:Y-m-d', 'performed_on' => 'date:Y-m-d', 'next_on' => 'date:Y-m-d', 'follow_up_dates' => 'array', 'unknown_fields' => 'array', 'verified_at' => 'datetime'];
    }
}
