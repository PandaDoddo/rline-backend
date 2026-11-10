<?php

namespace App\Modules\ProcessFlows\Http\Requests;

class UpdateProcessFlowNodeRequest extends ProcessFlowNodeRequest
{
    public function rules(): array
    {
        return ['structure_version' => 'required|integer|min:1', 'version' => 'required|integer|min:1', 'name' => 'sometimes|filled|string|max:255',
            'code' => 'sometimes|nullable|string|max:80',
            'parent_id' => 'sometimes|nullable|uuid', 'status' => 'sometimes|in:active,archived', 'kind' => 'prohibited', 'category' => 'prohibited', 'medium_kind' => 'prohibited'];
    }
}
