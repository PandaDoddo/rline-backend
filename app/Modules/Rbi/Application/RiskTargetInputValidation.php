<?php

namespace App\Modules\Rbi\Application;

use Illuminate\Support\Facades\Validator;

final class RiskTargetInputValidation
{
    public function normalize(array $input): array
    {
        if (is_array($input['values'] ?? null)) {
            foreach ($input['values'] as &$value) {
                if (is_string($value)) {
                    $number = str_replace([' ', "\u{00a0}", "\u{202f}", ','], ['', '', '', '.'], trim($value));
                    $value = $number === '' ? null : (is_numeric($number) ? $number + 0 : $value);
                }
            }
        }
        unset($value);

        return $input;
    }

    public function rules(): array
    {
        $fields = app(InstallationRiskCriteria::class)->schema()['fields'];
        $keys = implode(',', array_column($fields, 'key'));
        $rules = ['schema_version' => 'required|string|max:100', 'version' => 'required|integer|min:1', 'values' => 'sometimes|array:'.$keys, 'reset_fields' => 'sometimes|array|list|max:12', 'reset_fields.*' => 'required|string|distinct|in:'.$keys];
        foreach ($fields as $field) {
            $key = $field['key'];
            $rules['values.'.$key] = ['sometimes', $field['nullable'] ? 'nullable' : 'required', 'numeric', 'min:'.$field['min'], 'max:'.$field['max']];
            if ($key === 'H') {
                $rules['values.'.$key][] = 'multiple_of:0.5';
            }
        }

        return $rules;
    }

    public function validated(array $input): array
    {
        return Validator::make($this->normalize($input), $this->rules())->validate();
    }
}
