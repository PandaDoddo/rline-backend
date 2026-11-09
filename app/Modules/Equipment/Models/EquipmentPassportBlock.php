<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use App\Modules\Equipment\Domain\PassportSchema;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class EquipmentPassportBlock extends TenantModel
{
    protected $primaryKey = 'equipment_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['equipment_id'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    protected function casts(): array
    {
        return collect(PassportSchema::tables()[$this->getTable()])->map(fn ($field) => match ($field['type']) {
            'double' => 'float', 'integer' => 'integer', 'date' => 'date:Y-m-d', default => 'string',
        })->all();
    }
}
