<?php

namespace App\Modules\Rbi\Http\Requests;

use App\Http\Requests\TenantWriteRequest;
use App\Modules\Rbi\Application\ManagementInputValidation;

class UpdateManagementRequest extends TenantWriteRequest
{
    protected function prepareForValidation(): void
    {
        $this->replace(app(ManagementInputValidation::class)->normalize($this->all()));
    }

    public function rules(): array
    {
        return app(ManagementInputValidation::class)->rules();
    }
}
