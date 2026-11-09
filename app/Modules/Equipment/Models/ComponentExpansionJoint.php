<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentExpansionJoint extends TenantModel
{
    protected $table = 'equipment_component_expansion_joints';

    protected $primaryKey = 'component_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['joint_kind', 'jacket_present'];

    protected function casts(): array
    {
        return ['jacket_present' => 'boolean'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponent::class, 'component_id');
    }
}
