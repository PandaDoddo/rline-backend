<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use App\Tenancy\TenantAuthSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantAuthenticationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');

        return $tenant instanceof Tenant && app(TenantAuthSettings::class)->canManage($this->user(), $tenant);
    }

    public function rules(): array
    {
        return TenantAuthSettings::inputRules();
    }
}
