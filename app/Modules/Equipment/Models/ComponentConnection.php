<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComponentConnection extends TenantModel
{
    protected $table = 'equipment_component_connections';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = ['status' => 'active', 'position' => 0];

    protected $fillable = ['status', 'position', 'side', 'joining_method', 'collar_present', 'thread_kind', 'thread_size', 'notes', 'source_reference'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'collar_present' => 'boolean'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'component_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ComponentConnectionTarget::class, 'connection_id')->orderBy('position')->orderBy('target_component_id');
    }

    public function unresolvedTargets(): HasMany
    {
        return $this->hasMany(UnresolvedComponentTarget::class, 'connection_id')->orderBy('position')->orderBy('source_reference');
    }
}
