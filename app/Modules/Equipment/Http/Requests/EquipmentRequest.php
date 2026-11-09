<?php

namespace App\Modules\Equipment\Http\Requests;

use App\Modules\Equipment\Application\EquipmentInputValidation;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class EquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(app(TenantContext::class)->membership($this->user()->id)?->role, ['owner', 'admin'], true);
    }

    protected function prepareForValidation(): void
    {
        $this->replace(app(EquipmentInputValidation::class)->normalize($this->all()));
    }

    public function rules(): array
    {
        return app(EquipmentInputValidation::class)->rules();
    }
}
