<?php

namespace App\Modules\Rbi\Http\Requests;

use App\Http\Requests\TenantWriteRequest;
use App\Modules\Rbi\Application\RiskTargetInputValidation;

class UpdateRiskCriteriaRequest extends TenantWriteRequest
{
    protected function prepareForValidation(): void
    {
        $this->replace(app(RiskTargetInputValidation::class)->normalize($this->all()));
    }

    public function rules(): array
    {
        return app(RiskTargetInputValidation::class)->rules();
    }
}
