<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(app(TenantContext::class)->membership($this->user()->id)?->role, ['owner', 'admin'], true);
    }

    protected function prepareForValidation(): void
    {
        $passport = $this->input('passport');
        if (! is_array($passport)) {
            return;
        }
        foreach (config('equipment.passport') as $key => $definition) {
            if (! array_key_exists($key, $passport)) {
                continue;
            }
            if (is_string($passport[$key])) {
                $passport[$key] = trim($passport[$key]);
                if ($passport[$key] === '') {
                    $passport[$key] = null;
                } elseif (($definition['type'] ?? '') === 'number') {
                    $value = str_replace([' ', ','], ['', '.'], $passport[$key]);
                    if (is_numeric($value)) {
                        $passport[$key] = $value + 0;
                    }
                }
            }
        }
        if (isset($passport['commissioned_on']) && preg_match('/^[0-9]{4}$/D', (string) $passport['commissioned_on'])) {
            $passport['commissioned_on'] .= '-01-01';
            $passport['commissioned_precision'] = 'year';
        }
        if (isset($passport['registration_number']) && is_string($passport['registration_number'])) {
            $passport['registration_number'] = preg_replace('/\s*(РТН|ОТН)\s*$/u', '', $passport['registration_number']);
        }
        $this->merge(['passport' => $passport]);
        if (is_array($thickness = $this->input('thickness'))) {
            foreach ($thickness['stages'] ?? [] as $stageIndex => $stage) {
                if (! is_array($stage)) {
                    continue;
                }
                foreach ($stage['measurements'] ?? [] as $measurementIndex => $measurement) {
                    if (! is_array($measurement) || ! is_array($measurement['points'] ?? null)) {
                        continue;
                    }
                    $thickness['stages'][$stageIndex]['measurements'][$measurementIndex]['points'] = array_map(function ($value) {
                        if (! is_string($value)) {
                            return $value;
                        }
                        $value = str_replace([' ', ','], ['', '.'], trim($value));

                        return $value === '' ? null : (is_numeric($value) ? $value + 0 : $value);
                    }, $measurement['points']);
                }
            }
            $this->merge(['thickness' => $thickness]);
        }
        if (is_array($structure = $this->input('structure'))) {
            foreach ($structure as &$row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach (['diameter_mm', 'length_mm', 'nominal_thickness_mm', 'rejection_passport_mm', 'rejection_epb_mm'] as $key) {
                    if (isset($row[$key]) && is_string($row[$key])) {
                        $value = str_replace([' ', ','], ['', '.'], trim($row[$key]));
                        $row[$key] = $value === '' ? null : (is_numeric($value) ? $value + 0 : $value);
                    }
                }
            }
            unset($row);
            $this->merge(['structure' => $structure]);
        }
    }

    public function rules(): array
    {
        $keys = array_keys(config('equipment.passport'));
        $rules = ['installation_id' => ['required', 'uuid', Rule::exists('tenant.installations', 'id')],
            'profile_code' => ['required', Rule::in(array_keys(config('equipment.profiles')))],
            'passport' => 'required|array:'.implode(',', $keys), 'checks' => 'prohibited', 'version' => 'prohibited', 'status' => 'prohibited'];
        foreach (config('equipment.passport') as $key => $definition) {
            $rules['passport.'.$key] = $definition['rules'];
        }

        return $rules;
    }
}
