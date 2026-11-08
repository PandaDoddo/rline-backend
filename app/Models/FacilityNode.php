<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityNode extends TenantModel
{
    use HasUuids;

    protected $fillable = ['parent_id', 'kind', 'name', 'code'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
