<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ProcessFlowNodeRequest extends UpdateMaterialCostRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return ['structure_version' => 'required|integer|min:1', 'name' => 'required|string|min:1|max:255', 'kind' => 'required|in:folder,flow',
            'category' => ['required', Rule::in(array_keys(config('process-flows.categories')))], 'parent_id' => 'sometimes|nullable|uuid',
            'medium_kind' => ['sometimes', Rule::in(array_keys(config('process-flows.media')))]];
    }
}
