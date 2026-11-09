<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EquipmentComponent extends TenantModel
{
    use HasUuids;

    protected $hidden = ['cardProjection'];

    public function cardProjection(): HasOne
    {
        return $this->hasOne(ComponentCardProjection::class, 'component_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(ComponentConnection::class, 'component_id')->orderBy('position')->orderBy('id');
    }

    public function historyEntries(): HasMany
    {
        return $this->hasMany(ComponentHistoryEntry::class, 'component_id');
    }

    public function assemblies(): HasMany
    {
        return $this->hasMany(ComponentAssembly::class, 'component_id')->orderBy('position')->orderBy('id');
    }

    public function headGeometry(): HasOne
    {
        return $this->hasOne(ComponentHeadGeometry::class, 'component_id');
    }

    public function fittingGeometry(): HasOne
    {
        return $this->hasOne(ComponentFittingGeometry::class, 'component_id');
    }

    public function endConnection(): HasOne
    {
        return $this->hasOne(ComponentEndConnection::class, 'component_id');
    }

    public function bossGeometry(): HasOne
    {
        return $this->hasOne(ComponentBossGeometry::class, 'component_id');
    }

    public function valveDetails(): HasOne
    {
        return $this->hasOne(ComponentValveDetails::class, 'component_id');
    }

    public function tubeGeometry(): HasOne
    {
        return $this->hasOne(ComponentTubeGeometry::class, 'component_id');
    }

    public function coilGeometry(): HasOne
    {
        return $this->hasOne(ComponentCoilGeometry::class, 'component_id');
    }

    public function lensGeometry(): HasOne
    {
        return $this->hasOne(ComponentLensGeometry::class, 'component_id');
    }

    public function expansionJoint(): HasOne
    {
        return $this->hasOne(ComponentExpansionJoint::class, 'component_id');
    }

    public function shellGeometry(): HasOne
    {
        return $this->hasOne(ComponentShellGeometry::class, 'component_id');
    }

    public function construction(): HasOne
    {
        return $this->hasOne(ComponentConstruction::class, 'component_id');
    }

    protected $fillable = ['name', 'type_code', 'coil_role', 'status', 'position', 'material', 'diameter_mm', 'length_mm', 'nominal_thickness_mm', 'rejection_passport_mm', 'rejection_epb_mm', 'notes', 'flow_space'];

    public function ports(): HasMany
    {
        return $this->hasMany(ComponentPort::class, 'component_id')->orderBy('direction');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ComponentType::class, 'type_code', 'code');
    }

    protected $attributes = ['next_element_number' => 1, 'position' => 0, 'status' => 'active', 'is_coil' => false];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('code');
    }

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_coil' => 'boolean', 'next_element_number' => 'integer',
            'diameter_mm' => 'float', 'length_mm' => 'float', 'nominal_thickness_mm' => 'float', 'rejection_passport_mm' => 'float', 'rejection_epb_mm' => 'float'];
    }
}
