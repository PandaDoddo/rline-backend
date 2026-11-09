<?php

namespace App\Modules\Installations\Application;

use App\Modules\Installations\Domain\InstallationPassportSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class FacilityInputValidation
{
    public function validated(array $input, string $type, bool $updating = false, ?string $identity = null): array
    {
        return Validator::make($this->normalize($input), $this->rules($type, $updating, $identity))->validate();
    }

    public function normalize(array $input): array
    {
        $input = $this->strings($input);
        if (is_array($input['passport'] ?? null)) {
            foreach (InstallationPassportSchema::FIELDS as $key => [$column, $type]) {
                if ($type !== 'text' && is_string($input['passport'][$key] ?? null)) {
                    $number = str_replace([' ', "\u{00a0}", "\u{202f}", ','], ['', '', '', '.'], $input['passport'][$key]);
                    if (is_numeric($number)) {
                        $input['passport'][$key] = $number + 0;
                    }
                }
            }
        }

        return $input;
    }

    private function strings(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value) === '' ? null : trim($value);
        }
        if (is_array($value)) {
            foreach ($value as &$item) {
                $item = $this->strings($item);
            }
            unset($item);
        }

        return $value;
    }

    public function rules(string $type, bool $updating = false, ?string $identity = null): array
    {
        if (! in_array($type, ['node', 'installation'], true)) {
            throw new \InvalidArgumentException('Unknown facility write type.');
        }
        $node = $type === 'node';
        $table = $node ? 'facility_nodes' : 'installations';
        $rules = ['name' => 'required|string|min:2|max:255',
            'code' => ['sometimes', 'nullable', 'string', 'max:80', Rule::unique('tenant.'.$table, 'code')->ignore($identity)],
            'version' => $updating ? 'required|integer|min:1' : 'prohibited'];
        if ($node) {
            return $rules + ['kind' => $updating ? 'prohibited' : 'required|in:enterprise,site,production', 'parent_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tenant.facility_nodes', 'id')]];
        }

        return $rules + ['parent_id' => ['required', 'uuid', Rule::exists('tenant.facility_nodes', 'id')->where('kind', 'production')]] + InstallationPassportSchema::rules();
    }
}
