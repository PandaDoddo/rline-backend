<?php

namespace App\Modules\ProcessFlows\Http\Requests;

use App\Http\Requests\TenantWriteRequest;

class InitializeProcessFlowsRequest extends TenantWriteRequest
{
    protected function prepareForValidation(): void {}

    public function rules(): array
    {
        $keys = array_keys(config('process-flows.categories'));
        $rules = ['structure_version' => 'required|integer|min:1', 'request_id' => 'required|uuid', 'answers' => 'required|array:'.implode(',', $keys)];
        foreach ($keys as $key) {
            $rules['answers.'.$key] = 'required|array:enabled,comment';
            $rules['answers.'.$key.'.enabled'] = 'present|nullable|boolean';
            $rules['answers.'.$key.'.comment'] = 'present|nullable|string|max:2000';
        }

        return $rules;
    }
}
