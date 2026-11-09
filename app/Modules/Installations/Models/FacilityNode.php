<?php

namespace App\Modules\Installations\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityNode extends TenantModel
{
    use HasUuids;

    protected $fillable = ['parent_id', 'kind', 'name', 'code'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
