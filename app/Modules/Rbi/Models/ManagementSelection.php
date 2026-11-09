<?php

namespace App\Modules\Rbi\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read model for an answer's unique selected option; writes occur through the aggregate command. */
class ManagementSelection extends TenantModel
{
    protected $table = 'installation_management_selections';

    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['option_index' => 'integer'];
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(ManagementAnswer::class, 'answer_id');
    }
}
