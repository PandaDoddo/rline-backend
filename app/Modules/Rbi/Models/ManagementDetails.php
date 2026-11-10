<?php

namespace App\Modules\Rbi\Models;

use App\Models\TenantModel;

class ManagementDetails extends TenantModel
{
    protected $table = 'installation_management_details';

    protected $primaryKey = 'installation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['assessed_on', 'auditor_name', 'notes'];

    protected function casts(): array
    {
        return ['assessed_on' => 'immutable_date'];
    }

    public function publicValues(): array
    {
        return ['assessed_on' => $this->assessed_on?->format('Y-m-d'), 'auditor_name' => $this->auditor_name, 'notes' => $this->notes];
    }
}
