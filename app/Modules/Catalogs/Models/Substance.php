<?php

namespace App\Modules\Catalogs\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Substance extends TenantModel
{
    use HasUuids;

    protected $fillable = ['label', 'status'];

    protected $attributes = ['base_values' => '{}', 'base_raw' => '{}', 'base_flags' => '{}', 'status' => 'active', 'version' => 1, 'origin' => 'custom'];

    protected $with = ['cardProjection'];

    protected $hidden = ['cardProjection'];

    protected $appends = ['overrides', 'sources', 'checks'];

    protected function casts(): array
    {
        return ['base_values' => 'array', 'base_raw' => 'array', 'base_flags' => 'array', 'version' => 'integer'];
    }

    public function cardProjection(): HasOne
    {
        return $this->hasOne(SubstanceCardProjection::class, 'substance_id');
    }

    public function getOverridesAttribute(): array
    {
        return $this->cardProjection?->overrides ?? [];
    }

    public function getSourcesAttribute(): array
    {
        return $this->cardProjection?->sources ?? [];
    }

    public function getChecksAttribute(mixed $value): array
    {
        // List projection selects only checks, without eager-loading full cards.
        return $value !== null ? (is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value) : ($this->cardProjection?->checks ?? []);
    }

    public function effective(): array
    {
        return array_replace($this->base_values ?? [], $this->overrides ?? []);
    }

    public function needsReview(): bool
    {
        if (! $this->base_flags) {
            return false;
        }
        $keys = array_keys($this->base_flags['fields'] ?? []);
        if (! $keys) {
            return true;
        }
        foreach ($keys as $key) {
            if (! isset($this->checks[$key])) {
                return true;
            }
        }

        return false;
    }
}
