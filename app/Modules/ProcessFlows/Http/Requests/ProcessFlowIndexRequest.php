<?php

namespace App\Modules\ProcessFlows\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessFlowIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['parent_id' => 'sometimes|nullable|uuid', 'flat' => 'sometimes|boolean', 'q' => 'sometimes|nullable|string|max:255', 'kind' => 'sometimes|in:folder,flow', 'category' => ['sometimes', Rule::in(array_keys(config('process-flows.categories')))], 'status' => 'sometimes|in:active,archived', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
