<?php

namespace App\Modules\Installations\Http\Requests;

use App\Modules\Installations\Application\FacilityInputValidation;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class InstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(app(TenantContext::class)->membership($this->user()->id)?->role, ['owner', 'admin'], true);
    }

    protected function prepareForValidation(): void
    {
        $this->replace(app(FacilityInputValidation::class)->normalize($this->all()));
    }

    public function rules(): array
    {
        return app(FacilityInputValidation::class)->rules('installation', false, $this->route('installation'));
    }
}
