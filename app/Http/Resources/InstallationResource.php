<?php

namespace App\Http\Resources;

use App\Models\Installation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Installation */
class InstallationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $path = [];
        $parent = $this->parent;
        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return ['id' => (string) $this->id, 'parent_id' => (string) $this->parent_id, 'name' => $this->name,
            'code' => $this->code, 'passport' => (object) $this->passport, 'version' => (int) $this->version,
            'path' => implode(' → ', $path), 'created_by' => (int) $this->created_by,
            'updated_by' => (int) $this->updated_by, 'updated_at' => $this->updated_at->toIso8601String()];
    }
}
