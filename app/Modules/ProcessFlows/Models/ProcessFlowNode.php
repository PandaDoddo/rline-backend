<?php

namespace App\Modules\ProcessFlows\Models;

use App\Models\TenantModel;
use App\Modules\ProcessFlows\Domain\FlowScreening;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProcessFlowNode extends TenantModel
{
    use HasUuids;

    protected $fillable = ['name', 'basis', 'medium_kind', 'status'];

    protected $attributes = ['basis' => 'mass', 'medium_kind' => 'fluid', 'status' => 'active', 'version' => 1];

    protected $with = ['cardProjection', 'screeningProjection'];

    protected $hidden = ['cardProjection', 'screeningProjection', 'accepted_calculation_id'];

    protected $appends = ['attributes', 'sources', 'checks', 'accepted_calculation', 'screening'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function composition(): HasMany
    {
        return $this->hasMany(ProcessFlowComposition::class, 'flow_id')->orderBy('position')->orderBy('id');
    }

    public function cardProjection(): HasOne
    {
        return $this->hasOne(ProcessFlowCardProjection::class, 'flow_id');
    }

    public function screeningProjection(): HasOne
    {
        return $this->hasOne(ProcessFlowScreeningProjection::class, 'flow_id');
    }

    public function environment(): HasOne
    {
        return $this->hasOne(ProcessFlowEnvironment::class, 'flow_id');
    }

    public function getScreeningAttribute(): ?array
    {
        if (! FlowScreening::eligible((string) $this->category, (string) $this->medium_kind, (string) $this->kind)) {
            return null;
        }
        if ($this->screeningProjection !== null) {
            return $this->screeningProjection->state;
        }
        $ph = $this->getAttributesAttribute()['ph'] ?? null;
        if ($ph === null) {
            return null;
        }
        $source = $this->getSourcesAttribute()['ph'] ?? [];

        return ['values' => ['ph' => $ph], 'comments' => [], 'thresholds' => []] + array_filter(['updated_by' => $source['by'] ?? null, 'updated_at' => $source['at'] ?? null], fn ($value) => $value !== null);
    }

    public function properties(): HasOne
    {
        return $this->hasOne(ProcessFlowProperties::class, 'flow_id');
    }

    public function propertyAnnotations(): HasMany
    {
        return $this->hasMany(ProcessFlowPropertyAnnotation::class, 'flow_id');
    }

    public function acceptedCalculation(): BelongsTo
    {
        return $this->belongsTo(ProcessFlowCalculation::class, 'accepted_calculation_id');
    }

    public function getAttributesAttribute(): array
    {
        return $this->cardProjection?->getAttribute('attributes') ?? [];
    }

    public function getSourcesAttribute(): array
    {
        return $this->cardProjection?->sources ?? [];
    }

    public function getChecksAttribute(): array
    {
        return $this->cardProjection?->checks ?? [];
    }

    public function getAcceptedCalculationAttribute(): ?array
    {
        return $this->cardProjection?->accepted_calculation;
    }
}
