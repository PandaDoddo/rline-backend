<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Domain\Screening\ComponentScreeningSchema;
use Illuminate\Validation\Rule;

final class MechanismInputValidation
{
    public function normalize(array $input): array
    {
        $input = $this->trim($input);
        foreach (['vibration_present', 'hydrogen_partial_pressure_mpa'] as $key) {
            if (array_key_exists($key, is_array($input['inputs'] ?? null) ? $input['inputs'] : [])) {
                $value = $input['inputs'][$key];
                if ($key === 'vibration_present') {
                    $input['inputs'][$key] = $this->boolean($value);
                } elseif (is_string($value)) {
                    $number = str_replace([' ', "\u{00a0}", "\u{202f}", ','], ['', '', '', '.'], trim($value));
                    $input['inputs'][$key] = $number === '' ? null : (is_numeric($number) ? $number + 0 : $value);
                }
            }
        }
        foreach (['component_inputs', 'overrides', 'question_notes', 'reviews'] as $section) {
            foreach (is_array($input[$section] ?? null) ? $input[$section] : [] as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                foreach (['component_id', 'flow_id', 'source_signature', 'input_signature'] as $key) {
                    if (is_string($entry[$key] ?? null)) {
                        $input[$section][$index][$key] = strtolower(trim($entry[$key]));
                    }
                }
                foreach (['vibration_excluded', 'tank_floor', 'verified'] as $key) {
                    if (array_key_exists($key, $entry)) {
                        $input[$section][$index][$key] = $this->boolean($entry[$key]);
                    }
                }
            }
        }

        return $input;
    }

    private function trim(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value) === '' ? null : trim($value);
        }
        if (is_array($value)) {
            foreach ($value as &$item) {
                $item = $this->trim($item);
            }
        }
        unset($item);

        return $value;
    }

    private function boolean(mixed $value): mixed
    {
        return in_array($value, [0, 1, '0', '1'], true) ? (bool) (int) $value : $value;
    }

    public static function rules(): array
    {
        $p = 'mechanisms';
        $schema = ComponentScreeningSchema::metadata();
        $mechanisms = array_column($schema['mechanisms'], 'id');
        $questions = [];
        foreach ($schema['mechanisms'] as $mechanism) {
            foreach ($mechanism['questions'] as $question) {
                $questions[] = $question['id'];
            }
        }

        $rules = [$p => 'sometimes|required|array:schema_version,inputs,component_inputs,overrides,question_notes,reviews', "$p.schema_version" => 'required_with:mechanisms|string|max:100',
            "$p.inputs" => 'sometimes|required|array:vibration_present,hydrogen_partial_pressure_mpa,vibration_notes,hydrogen_notes',
            "$p.inputs.vibration_present" => 'sometimes|nullable|boolean', "$p.inputs.hydrogen_partial_pressure_mpa" => 'sometimes|nullable|numeric|min:0|max:100000',
            "$p.inputs.vibration_notes" => 'sometimes|nullable|string|max:4000', "$p.inputs.hydrogen_notes" => 'sometimes|nullable|string|max:4000',
            "$p.component_inputs" => 'sometimes|array|list|max:2000', "$p.component_inputs.*" => 'required|array:component_id,vibration_excluded,tank_floor,vibration_notes,tank_floor_notes',
            "$p.component_inputs.*.component_id" => 'required|uuid|distinct', "$p.component_inputs.*.vibration_excluded" => 'sometimes|nullable|boolean', "$p.component_inputs.*.tank_floor" => 'sometimes|nullable|boolean',
            "$p.component_inputs.*.vibration_notes" => 'sometimes|nullable|string|max:4000', "$p.component_inputs.*.tank_floor_notes" => 'sometimes|nullable|string|max:4000',
            "$p.overrides" => 'sometimes|array|list|max:2000', "$p.overrides.*" => 'required|array:component_id,mechanism_code,side,state,reason,source_signature',
            "$p.overrides.*.component_id" => 'required|uuid', "$p.overrides.*.mechanism_code" => 'required|in:'.implode(',', $mechanisms), "$p.overrides.*.side" => 'required|in:internal,external,through',
            "$p.overrides.*.state" => 'present|nullable|in:active,potential,inactive', "$p.overrides.*.reason" => 'sometimes|nullable|string|max:5000', "$p.overrides.*.source_signature" => 'sometimes|nullable|regex:/^[a-f0-9]{64}$/D',
            "$p.question_notes" => 'sometimes|array|list|max:2000', "$p.question_notes.*" => 'required|array:component_id,question_id,side,flow_id,note',
            "$p.question_notes.*.component_id" => 'required|uuid', "$p.question_notes.*.question_id" => ['required', Rule::in(array_unique($questions))], "$p.question_notes.*.side" => 'required|in:internal,external,through',
            "$p.question_notes.*.flow_id" => 'present|nullable|uuid', "$p.question_notes.*.note" => 'present|nullable|string|max:4000',
            "$p.reviews" => 'sometimes|array|list|max:2000', "$p.reviews.*" => 'required|array:component_id,verified,input_signature', "$p.reviews.*.component_id" => 'required|uuid|distinct',
            "$p.reviews.*.verified" => 'required|boolean', "$p.reviews.*.input_signature" => 'sometimes|nullable|regex:/^[a-f0-9]{64}$/D'];
        foreach ($rules as &$rule) {
            if (is_string($rule) && str_contains($rule, '|string|')) {
                $rule .= '|not_regex:/\x00/u';
            }
        }
        unset($rule);

        return $rules;
    }
}
