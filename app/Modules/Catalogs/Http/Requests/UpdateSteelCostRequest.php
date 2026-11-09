<?php

namespace App\Modules\Catalogs\Http\Requests;

use App\Http\Requests\TenantWriteRequest;

class UpdateSteelCostRequest extends TenantWriteRequest
{
    protected function prepareForValidation(): void
    {
        $value = $this->input('values.accepted_cost');
        if (is_string($value)) {
            $values = $this->input('values');
            $normalized = str_replace(',', '.', trim($value));
            $values['accepted_cost'] = $normalized === '' ? null : (is_numeric($normalized) ? (float) $normalized : $value);
            $this->merge(['values' => $values]);
        }
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'values' => 'sometimes|array:accepted_cost,cost_reason',
            'values.accepted_cost' => 'sometimes|nullable|numeric|gt:0|max:1000000', 'values.cost_reason' => 'sometimes|nullable|string|max:4000',
            'reset_fields' => 'sometimes|array|max:2', 'reset_fields.*' => 'required|distinct|in:accepted_cost,cost_reason',
            'sources' => 'sometimes|array:accepted_cost,cost_reason', 'sources.*' => 'required|array:citation,conditions', 'sources.*.citation' => 'nullable|string|max:4000', 'sources.*.conditions' => 'nullable|string|max:4000',
            'verification' => 'sometimes|array:accepted_cost,cost_reason', 'verification.accepted_cost' => 'sometimes|boolean', 'verification.cost_reason' => 'sometimes|boolean'];
    }
}
