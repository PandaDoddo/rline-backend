<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentPort extends TenantModel
{
    protected $table = 'equipment_component_ports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['mark', 'service_name', 'connection_kind'];

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'component_id');
    }
}
