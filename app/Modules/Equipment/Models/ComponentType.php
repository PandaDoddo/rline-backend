<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComponentType extends TenantModel
{
    protected $table = 'equipment_component_types';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['element_allowed' => 'boolean', 'position' => 'integer'];
    }

    public function components(): HasMany
    {
        return $this->hasMany(EquipmentComponent::class, 'type_code', 'code');
    }
}
