<?php

namespace App\Modules\Catalogs\Http\Requests;

use App\Http\Requests\TenantWriteRequest;
use App\Modules\Catalogs\Application\SubstanceInputValidation;

class SubstanceRequest extends TenantWriteRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(app(SubstanceInputValidation::class)->normalize($this->all()));
    }

    public function rules(): array
    {
        return app(SubstanceInputValidation::class)->rules();
    }
}
