<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;

/** Read projection over typed tables; JSON is produced by SELECT, never stored here. */
class EquipmentPassportProjection extends TenantModel
{
    protected $table = 'equipment_passports';

    protected $primaryKey = 'equipment_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['passport' => 'array', 'qualifiers' => 'array', 'checks' => 'array'];
    }
}
