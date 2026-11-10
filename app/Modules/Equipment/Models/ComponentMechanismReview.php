<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;

class ComponentMechanismReview extends TenantModel
{
    protected $table = 'equipment_mechanism_reviews';

    protected $primaryKey = 'component_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['verified_by' => 'integer', 'verified_at' => 'immutable_datetime'];
    }
}
