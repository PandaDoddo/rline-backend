<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Composite identity is enforced by PostgreSQL; writes use the aggregate's batch upsert. */
class ComponentConnectionTarget extends TenantModel
{
    protected $table = 'equipment_component_connection_targets';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['position' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ComponentConnection::class, 'connection_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'target_component_id');
    }
}
