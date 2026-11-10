<?php

namespace App\Modules\Installations\Http\Requests;

use App\Http\Requests\TenantWriteRequest;
use App\Modules\Installations\Application\InstallationCostInputValidation;

class UpdateInstallationCostRequest extends TenantWriteRequest
{
    protected function prepareForValidation(): void
    {
        $this->replace(app(InstallationCostInputValidation::class)->normalize($this->all()));
    }

    public function rules(): array
    {
        return app(InstallationCostInputValidation::class)->rules();
    }
}
