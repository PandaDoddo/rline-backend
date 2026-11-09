<?php

namespace App\Modules\DataExchange\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentImportOrigin extends TenantModel
{
    protected $table = 'dataset_import_equipment';

    protected $primaryKey = 'equipment_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DatasetImportBatch::class, 'batch_id');
    }

    protected function casts(): array
    {
        return ['imported_version' => 'integer'];
    }
}
