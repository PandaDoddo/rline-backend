<?php

namespace App\Http\Resources;

use App\Models\FacilityNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FacilityNode */
class FacilityNodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $path = [];
        $parent = $this->parent;
        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return ['id' => (string) $this->id, 'parent_id' => $this->parent_id, 'kind' => $this->kind,
            'name' => $this->name, 'code' => $this->code, 'version' => (int) $this->version,
            'path' => implode(' → ', $path)];
    }
}
