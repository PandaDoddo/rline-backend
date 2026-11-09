<?php

namespace App\Modules\DataExchange\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/** Completed receipt; source JSON is immutable evidence, never a mutable business record. */
class DatasetImportBatch extends TenantModel
{
    use HasUuids;

    protected $table = 'dataset_import_batches';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['manifest' => 'array', 'summary' => 'array', 'applied_by' => 'integer', 'source_snapshot_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];
    }
}
