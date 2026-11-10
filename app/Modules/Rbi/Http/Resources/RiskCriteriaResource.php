<?php

namespace App\Modules\Rbi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Fluent;

/** @property-read Fluent $resource */
class RiskCriteriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['installation_id' => (string) $this->installation_id, 'version' => (int) $this->version, 'schema_version' => (string) $this->schema_version, 'policy' => (string) $this->policy,
            'overrides' => (object) $this->overrides, 'effective' => (object) $this->effective, 'origins' => (object) $this->origins, 'current_mri_months' => $this->current_mri_months, 'as_of' => (string) $this->as_of, 'data_origin' => $this->data_origin];
    }
}
