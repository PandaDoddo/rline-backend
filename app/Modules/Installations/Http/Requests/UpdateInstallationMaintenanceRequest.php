<?php

namespace App\Modules\Installations\Http\Requests;

use App\Http\Requests\TenantWriteRequest;
use App\Modules\Installations\Application\InstallationMaintenanceInputValidation;

class UpdateInstallationMaintenanceRequest extends TenantWriteRequest
{
    protected function prepareForValidation(): void
    {
        $this->replace(app(InstallationMaintenanceInputValidation::class)->normalize($this->all()));
    }

    public function rules(): array
    {
        return app(InstallationMaintenanceInputValidation::class)->rules();
    }
}
