<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMaterialCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(app(TenantContext::class)->membership($this->user()->id)?->role, ['owner', 'admin'], true);
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('values.factor');
        if (is_string($value)) {
            $values = $this->input('values');
            $normalized = str_replace(',', '.', trim($value));
            $values['factor'] = $normalized === '' ? null : (is_numeric($normalized) ? (float) $normalized : $value);
            $this->merge(['values' => $values]);
        }
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'values' => 'sometimes|array:name,factor', 'values.name' => 'sometimes|filled|string|max:255',
            'values.factor' => 'sometimes|nullable|numeric|gt:0|max:1000000', 'reset_fields' => 'sometimes|array|max:2', 'reset_fields.*' => 'required|distinct|in:name,factor',
            'verification' => 'sometimes|array:name,factor', 'verification.name' => 'sometimes|boolean', 'verification.factor' => 'sometimes|boolean',
            'sources' => 'sometimes|array:name,factor', 'sources.*' => 'required|array:citation,conditions', 'sources.*.citation' => 'nullable|string|max:4000', 'sources.*.conditions' => 'nullable|string|max:4000',
            'base_values' => 'prohibited', 'base_raw' => 'prohibited', 'status' => 'prohibited', 'material_group' => 'prohibited', 'english_name' => 'prohibited', 'checks' => 'prohibited'];
    }
}
