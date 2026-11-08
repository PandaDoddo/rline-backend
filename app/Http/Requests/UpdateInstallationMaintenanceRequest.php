<?php

namespace App\Http\Requests;

class UpdateInstallationMaintenanceRequest extends UpdateMaterialCostRequest
{
    protected function prepareForValidation(): void
    {
        $entries = $this->input('upserts');
        if (is_array($deleted = $this->input('delete_ids'))) {
            $this->merge(['delete_ids' => array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $deleted)]);
        }
        if (! is_array($entries)) {
            return;
        }
        foreach ($entries as &$entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (is_string($entry['id'] ?? null)) {
                $entry['id'] = strtolower($entry['id']);
            }
            if (! is_array($entry['values'] ?? null)) {
                continue;
            }
            foreach ($entry['values'] as &$value) {
                if (is_string($value)) {
                    $value = trim($value) === '' ? null : trim($value);
                }
            }
            unset($value);
        }
        unset($entry);
        $this->merge(['upserts' => $entries]);
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'no_stops' => 'sometimes|nullable|boolean', 'upserts' => 'sometimes|array|list|max:200',
            'upserts.*' => 'required|array:id,kind,values,verified,unlock', 'upserts.*.id' => 'required|uuid|distinct', 'upserts.*.kind' => 'required|in:mri,repair,stop',
            'upserts.*.values' => 'sometimes|array:effective_date,interval_months,starts_on,ends_on,repair_kind,starts_at,ends_at,reason',
            'upserts.*.values.effective_date' => 'sometimes|nullable|date_format:Y-m-d', 'upserts.*.values.interval_months' => 'sometimes|nullable|integer|min:1|max:1200',
            'upserts.*.values.starts_on' => 'sometimes|nullable|date_format:Y-m-d', 'upserts.*.values.ends_on' => 'sometimes|nullable|date_format:Y-m-d',
            'upserts.*.values.repair_kind' => 'sometimes|nullable|in:capital,extended_medium,medium', 'upserts.*.values.reason' => 'sometimes|nullable|string|max:5000',
            'upserts.*.values.starts_at' => ['sometimes', 'nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'upserts.*.values.ends_at' => ['sometimes', 'nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'upserts.*.verified' => 'sometimes|boolean', 'upserts.*.unlock' => 'sometimes|boolean', 'delete_ids' => 'sometimes|array|list|max:200', 'delete_ids.*' => 'required|uuid|distinct'];
    }
}
