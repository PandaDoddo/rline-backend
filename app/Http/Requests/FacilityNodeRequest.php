<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FacilityNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(app(TenantContext::class)->membership($this->user()->id)?->role, ['owner', 'admin'], true);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
        if (is_string($this->input('code'))) {
            $this->merge(['code' => trim($this->input('code')) ?: null]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:255',
            'code' => ['sometimes', 'nullable', 'string', 'max:80', Rule::unique('tenant.facility_nodes', 'code')->ignore($this->route('node'))],
            'kind' => 'required|in:enterprise,site,production',
            'parent_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tenant.facility_nodes', 'id')],
            'version' => 'prohibited',
        ];
    }
}
