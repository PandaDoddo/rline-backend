<?php

namespace App\Modules\Equipment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The complete question trace of one physical element, fetched on demand.
 *
 * @mixin \stdClass
 */
class MechanismElementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
