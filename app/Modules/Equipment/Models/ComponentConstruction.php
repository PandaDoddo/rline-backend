<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use App\Modules\Equipment\Domain\ComponentConstructionSchema;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentConstruction extends TenantModel
{
    protected $table = 'equipment_component_constructions';

    protected $primaryKey = 'component_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public function getFillable(): array
    {
        return array_keys(ComponentConstructionSchema::LABELS);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'component_id');
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'nominal_pressure_mpa' => 'float'];
    }
}
