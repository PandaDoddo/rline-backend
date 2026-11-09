<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentEndConnection extends TenantModel
{
    protected $table = 'equipment_component_end_connections';

    protected $primaryKey = 'component_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['far_end_kind', 'thread_kind', 'thread_size', 'instrument_connection', 'instrument_kind'];

    protected function casts(): array
    {
        return ['instrument_connection' => 'boolean'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'component_id');
    }
}
