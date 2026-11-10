<?php

namespace App\Modules\ProcessFlows\Http\Requests;

use App\Http\Requests\TenantWriteRequest;
use App\Modules\ProcessFlows\Application\FlowInputValidation;

class UpdateProcessFlowRequest extends TenantWriteRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(app(FlowInputValidation::class)->normalize($this->all()));
    }

    public function rules(): array
    {
        return app(FlowInputValidation::class)->cardRules();
    }
}
