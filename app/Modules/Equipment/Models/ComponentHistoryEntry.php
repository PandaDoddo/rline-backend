<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentHistoryEntry extends TenantModel
{
    protected $table = 'equipment_component_history';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['kind', 'occurred_on', 'actor_name', 'description', 'source_reference'];

    protected function casts(): array
    {
        return ['occurred_on' => 'immutable_date:Y-m-d', 'recorded_by' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'component_id');
    }
}
