<?php

namespace App\Modules\Diagnostics\Models;

use App\Models\TenantModel;
use App\Modules\Diagnostics\Domain\VisualInspectionSchema;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class VisualInspectionObservation extends TenantModel
{
    use HasUuids;

    protected $table = 'equipment_visual_observations';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        $casts = [];
        foreach (VisualInspectionSchema::fields() as $key => $field) {
            if (in_array($field['type'], ['boolean', 'number'], true)) {
                $casts[$key] = $field['type'] === 'boolean' ? 'boolean' : 'float';
            }
        }

        return $casts;
    }

    public function publicValues(): array
    {
        return array_combine(array_keys(VisualInspectionSchema::fields()), array_map(fn ($key) => $this->getAttribute($key), array_keys(VisualInspectionSchema::fields())));
    }
}
