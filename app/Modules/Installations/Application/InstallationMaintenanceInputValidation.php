<?php

namespace App\Modules\Installations\Application;

use Illuminate\Support\Facades\Validator;

final class InstallationMaintenanceInputValidation
{
    public function validated(array $input): array
    {
        return Validator::make($this->normalize($input), $this->rules())->validate();
    }

    public function normalize(array $input): array
    {
        if (array_key_exists('no_stops', $input)) {
            $input['no_stops'] = $this->boolean($input['no_stops']);
        }
        if (is_array($input['delete_ids'] ?? null)) {
            $input['delete_ids'] = array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $input['delete_ids']);
        }
        if (! is_array($input['upserts'] ?? null)) {
            return $input;
        }
        foreach ($input['upserts'] as &$entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (is_string($entry['id'] ?? null)) {
                $entry['id'] = strtolower($entry['id']);
            }
            foreach (['verified', 'unlock'] as $key) {
                if (array_key_exists($key, $entry)) {
                    $entry[$key] = $this->boolean($entry[$key]);
                }
            }
            if (is_array($entry['values'] ?? null)) {
                foreach ($entry['values'] as &$value) {
                    if (is_string($value)) {
                        $value = trim($value) === '' ? null : trim($value);
                    }
                }
            }
            unset($value);
        }
        unset($entry);

        return $input;
    }

    private function boolean(mixed $value): mixed
    {
        return in_array($value, [0, 1, '0', '1'], true) ? (bool) (int) $value : $value;
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
