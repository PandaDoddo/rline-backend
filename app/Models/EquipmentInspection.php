<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EquipmentInspection extends TenantModel
{
    use HasUuids;

    protected $fillable = ['kind', 'planned_on', 'performed_on', 'report_number', 'notes'];

    protected $attributes = ['is_primary' => false];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'planned_on' => 'date:Y-m-d', 'performed_on' => 'date:Y-m-d'];
    }
}
