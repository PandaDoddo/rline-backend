<?php

namespace App\Modules\ProcessFlows\Http\Resources;

use App\Modules\ProcessFlows\Models\ProcessFlowNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProcessFlowNode */
class ProcessFlowNodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'installation_id' => (string) $this->installation_id, 'parent_id' => $this->parent_id, 'kind' => (string) $this->kind, 'category' => (string) $this->category,
            'name' => (string) $this->name, 'code' => $this->code, 'data_origin' => $this->resource->getAttribute('import_origin'), 'path' => (string) ($this->path ?? $this->name), 'medium_kind' => (string) $this->medium_kind, 'status' => (string) $this->status, 'version' => (int) $this->version, 'children_count' => (int) ($this->children_count ?? 0)];
    }
}
