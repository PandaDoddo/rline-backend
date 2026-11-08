<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \stdClass */
class InstallationMaintenanceSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return get_object_vars($this->resource);
    }
}
