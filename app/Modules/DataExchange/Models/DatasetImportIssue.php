<?php

namespace App\Modules\DataExchange\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DatasetImportIssue extends TenantModel
{
    use HasUuids;

    protected $table = 'dataset_import_issues';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['source_value' => 'json'];
    }
}
