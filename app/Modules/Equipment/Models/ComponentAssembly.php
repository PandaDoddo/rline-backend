<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComponentAssembly extends TenantModel
{
    use HasUuids;

    protected $table = 'equipment_component_assemblies';

    protected $fillable = ['status', 'position', 'after_component_id', 'nominal_pressure_mpa', 'joint_kind', 'jacket_present', 'manufacturing_standard', 'notes', 'source_reference'];

    protected $attributes = ['status' => 'active', 'position' => 0];

    public function members(): HasMany
    {
        return $this->hasMany(ComponentAssemblyMember::class, 'assembly_id')->orderBy('position')->orderBy('member_component_id');
    }

    protected function casts(): array
    {
        return ['position' => 'integer', 'nominal_pressure_mpa' => 'float', 'jacket_present' => 'boolean'];
    }
}
