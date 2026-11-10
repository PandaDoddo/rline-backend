<?php

namespace App\Modules\Installations\Models;

use App\Models\TenantModel;

/** Current cost inputs; accepted calculation snapshots live separately. */
class InstallationCostParameters extends TenantModel
{
    protected $table = 'installation_cost_parameters';

    protected $primaryKey = 'installation_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['installation_id', 'envcost', 'injcost', 'popdens', 'prodcost', 'equipcost', 'costfactor', 'outagemult', 'detsys', 'isosys', 'mitsys'];

    protected function casts(): array
    {
        return ['envcost' => 'float', 'injcost' => 'float', 'popdens' => 'float', 'prodcost' => 'float', 'equipcost' => 'float',
            'costfactor' => 'float', 'outagemult' => 'float', 'detsys' => 'string', 'isosys' => 'string', 'mitsys' => 'string'];
    }
}
