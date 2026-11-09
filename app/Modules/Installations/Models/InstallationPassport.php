<?php

namespace App\Modules\Installations\Models;

use App\Models\TenantModel;
use App\Modules\Installations\Domain\InstallationPassportSchema;

class InstallationPassport extends TenantModel
{
    protected $table = 'installation_passports';

    protected $primaryKey = 'installation_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['installation_id'];

    public function publicValues(): array
    {
        $values = [];
        foreach (InstallationPassportSchema::FIELDS as $key => [$column]) {
            $value = $this->getAttribute($column);
            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    protected function casts(): array
    {
        $casts = [];
        foreach (InstallationPassportSchema::FIELDS as [$column, $type]) {
            if ($type !== 'text') {
                $casts[$column] = $type === 'integer' ? 'integer' : 'float';
            }
        }

        return $casts;
    }
}
