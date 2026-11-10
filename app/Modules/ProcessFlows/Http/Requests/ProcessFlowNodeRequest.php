<?php

namespace App\Modules\ProcessFlows\Http\Requests;

use App\Http\Requests\TenantWriteRequest;
use App\Modules\ProcessFlows\Application\FlowInputValidation;

class ProcessFlowNodeRequest extends TenantWriteRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
        if (is_string($this->input('code'))) {
            $this->merge(['code' => trim($this->input('code')) ?: null]);
        }
    }

    public function rules(): array
    {
        return app(FlowInputValidation::class)->nodeRules();
    }
}
