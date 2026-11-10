<?php

namespace App\Modules\Catalogs\Http\Requests;

use App\Modules\Catalogs\Application\SubstanceInputValidation;

class UpdateSubstanceRequest extends SubstanceRequest
{
    public function rules(): array
    {
        return app(SubstanceInputValidation::class)->rules(true);
    }
}
