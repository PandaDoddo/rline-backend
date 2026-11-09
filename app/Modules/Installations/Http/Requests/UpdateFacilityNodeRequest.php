<?php

namespace App\Modules\Installations\Http\Requests;

use App\Modules\Installations\Application\FacilityInputValidation;

class UpdateFacilityNodeRequest extends FacilityNodeRequest
{
    public function rules(): array
    {
        return app(FacilityInputValidation::class)->rules('node', true, $this->route('node'));
    }
}
