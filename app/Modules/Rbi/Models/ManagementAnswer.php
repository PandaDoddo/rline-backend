<?php

namespace App\Modules\Rbi\Models;

use App\Models\TenantModel;
use App\Modules\Installations\Models\Installation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagementAnswer extends TenantModel
{
    use HasUuids;

    protected $table = 'installation_management_answers';

    protected $fillable = ['state', 'boolean_value', 'score_value', 'comment', 'updated_by'];

    protected function casts(): array
    {
        return ['boolean_value' => 'boolean', 'score_value' => 'float'];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function selections(): HasMany
    {
        return $this->hasMany(ManagementSelection::class, 'answer_id')->orderBy('option_index');
    }
}
