<?php

namespace App\Modules\Installations\Http\Requests;

use App\Modules\Installations\Application\FacilityInputValidation;

class UpdateInstallationRequest extends InstallationRequest
{
    public function rules(): array
    {
        return app(FacilityInputValidation::class)->rules('installation', true, $this->route('installation'));
    }
}
