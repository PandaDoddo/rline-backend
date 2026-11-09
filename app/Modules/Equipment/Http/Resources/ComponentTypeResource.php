<?php

namespace App\Modules\Equipment\Http\Resources;

use App\Modules\Equipment\Models\ComponentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ComponentType */
class ComponentTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['code' => (string) $this->code, 'name' => (string) $this->name, 'root_kind' => $this->root_kind, 'element_allowed' => (bool) $this->element_allowed, 'position' => (int) $this->position];
    }
}
