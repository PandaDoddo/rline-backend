<?php

namespace App\Modules\Catalogs\Models;

use App\Models\TenantModel;

/** Read-only API projection; editable facts live in typed properties and annotations. */
class SubstanceCardProjection extends TenantModel
{
    protected $table = 'substance_cards';

    protected $primaryKey = 'substance_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['overrides' => 'array', 'sources' => 'array', 'checks' => 'array'];
    }
}
