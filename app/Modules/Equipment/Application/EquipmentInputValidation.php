<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Diagnostics\Application\EquipmentDiagnostics;
use App\Modules\Diagnostics\Application\InspectionCategoryInputValidation;
use App\Modules\Diagnostics\Application\VisualInspectionInputValidation;
use App\Modules\Equipment\Domain\ComponentDetailSchema;
use App\Modules\Equipment\Domain\OperatingScopeSchema;
use App\Modules\ProcessFlows\Application\EquipmentFlowContacts;
use App\Modules\ProcessFlows\Application\EquipmentFlowSelection;
use App\Modules\Rbi\Application\ComponentRbiInputValidation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** The same canonical write contract for HTTP and maintenance/import commands. */
final class EquipmentInputValidation
{
    public function validated(array $input, bool $updating = false): array
    {
        return Validator::make($this->normalize($this->trimStrings($input)), $this->rules($updating))->validate();
    }

    public function normalize(array $input): array
    {
        if (is_array($input['component_rbi'] ?? null)) {
            $input['component_rbi'] = app(ComponentRbiInputValidation::class)->normalize($input['component_rbi']);
        }
        if (is_array($input['mechanisms'] ?? null)) {
            $input['mechanisms'] = app(MechanismInputValidation::class)->normalize($input['mechanisms']);
        }
        if (is_array($input['inspection_categories'] ?? null)) {
            $input['inspection_categories'] = app(InspectionCategoryInputValidation::class)->normalize($input['inspection_categories']);
        }
        if (is_array($input['visual_inspections'] ?? null)) {
            $input['visual_inspections'] = app(VisualInspectionInputValidation::class)->normalize($input['visual_inspections']);
        }
        if (is_array($input['passport'] ?? null)) {
            $passport = $input['passport'];
            $qualifiers = array_key_exists('passport_qualifiers', $input) ? $input['passport_qualifiers'] : [];
            foreach (config('equipment.passport') as $key => $definition) {
                if (! array_key_exists($key, $passport)) {
                    continue;
                }
                if (($definition['type'] ?? '') === 'date' && is_scalar($passport[$key]) && preg_match('/^[0-9]{4}$/D', (string) $passport[$key])) {
                    $passport[$key] .= '-01-01';
                    if (is_array($qualifiers) && (! isset($qualifiers[$key]) || is_array($qualifiers[$key]))) {
                        $qualifiers[$key] = array_replace($qualifiers[$key] ?? [], ['precision' => 'year']);
                    }
                    if ($key === 'commissioned_on') {
                        $passport['commissioned_precision'] = 'year';
                    }
                }
                if (($definition['type'] ?? '') === 'number') {
                    $passport[$key] = $this->decimal($passport[$key]);
                }
            }
            if (isset($passport['registration_number']) && is_string($passport['registration_number'])) {
                $passport['registration_number'] = preg_replace('/\s*(РТН|ОТН)\s*$/u', '', trim($passport['registration_number']));
            }
            $input['passport'] = $passport;
            if ($qualifiers !== [] || array_key_exists('passport_qualifiers', $input)) {
                $input['passport_qualifiers'] = $qualifiers;
            }
        }
        if (is_array($input['structure'] ?? null)) {
            foreach ($input['structure'] as &$item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach (['diameter_mm', 'length_mm', 'nominal_thickness_mm', 'rejection_passport_mm', 'rejection_epb_mm'] as $field) {
                    if (array_key_exists($field, $item)) {
                        $item[$field] = $this->decimal($item[$field]);
                    }
                }
                if (is_array($item['construction'] ?? null) && array_key_exists('nominal_pressure_mpa', $item['construction'])) {
                    $item['construction']['nominal_pressure_mpa'] = $this->decimal($item['construction']['nominal_pressure_mpa']);
                }
                if (is_array($item['assemblies'] ?? null)) {
                    foreach ($item['assemblies'] as &$assembly) {
                        if (is_array($assembly) && array_key_exists('nominal_pressure_mpa', $assembly)) {
                            $assembly['nominal_pressure_mpa'] = $this->decimal($assembly['nominal_pressure_mpa']);
                        }
                    }
                    unset($assembly);
                }
                foreach (ComponentDetailSchema::BLOCKS as $block => $fields) {
                    if (! is_array($item[$block] ?? null)) {
                        continue;
                    }
                    foreach ($fields as $field => [$label, $type]) {
                        if (array_key_exists($field, $item[$block]) && in_array($type, ['positive', 'angle', 'integer'], true)) {
                            $item[$block][$field] = $this->decimal($item[$block][$field]);
                        }
                    }
                }
            }
            unset($item);
        }
        if (is_array($input['thickness'] ?? null) && is_array($input['thickness']['stages'] ?? null)) {
            foreach ($input['thickness']['stages'] as &$stage) {
                if (! is_array($stage) || ! is_array($stage['measurements'] ?? null)) {
                    continue;
                }
                foreach ($stage['measurements'] as &$measurement) {
                    if (is_array($measurement) && is_array($measurement['points'] ?? null)) {
                        $measurement['points'] = array_map($this->decimal(...), $measurement['points']);
                    }
                }
                unset($measurement);
            }
            unset($stage);
        }
        if (is_array($input['operating_scopes'] ?? null)) {
            $fields = OperatingScopeSchema::fields();
            foreach ($input['operating_scopes'] as &$scope) {
                if (! is_array($scope) || ! is_array($scope['values'] ?? null)) {
                    continue;
                }
                foreach ($scope['values'] as $key => &$value) {
                    if (($fields[$key]['type'] ?? null) === 'number') {
                        $value = $this->decimal($value);
                    }
                    if (($fields[$key]['type'] ?? null) === 'date' && is_scalar($value) && preg_match('/^[0-9]{4}$/D', (string) $value)) {
                        $value .= '-01-01';
                        if (! array_key_exists('qualifiers', $scope)) {
                            $scope['qualifiers'] = [];
                        }
                        if (is_array($scope['qualifiers']) && (! isset($scope['qualifiers'][$key]) || is_array($scope['qualifiers'][$key]))) {
                            $scope['qualifiers'][$key] = array_replace($scope['qualifiers'][$key] ?? [], ['precision' => 'year']);
                        }
                    }
                }
                unset($value);
            }
            unset($scope);
        }

        return $input;
    }

    private function decimal(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $text = trim($value);
        $number = str_replace([' ', "\u{00a0}", "\u{202f}", ','], ['', '', '', '.'], $text);

        return $text === '' ? null : (is_numeric($number) ? $number + 0 : $value);
    }

    private function trimStrings(mixed $input): mixed
    {
        if (is_string($input)) {
            return trim($input) === '' ? null : trim($input);
        }
        if (is_array($input)) {
            foreach ($input as &$value) {
                $value = $this->trimStrings($value);
            }
            unset($value);
        }

        return $input;
    }

    public function rules(bool $updating = false): array
    {
        $keys = array_keys(config('equipment.passport'));
        $rules = ['installation_id' => ['required', 'uuid', Rule::exists('tenant.installations', 'id')->where('status', 'active')],
            'profile_code' => ['required', Rule::in(array_keys(config('equipment.profiles')))],
            'passport' => 'required|array:'.implode(',', $keys), 'passport_qualifiers' => 'sometimes|array:'.implode(',', $keys), 'checks' => 'prohibited', 'version' => 'prohibited', 'status' => 'prohibited'];
        foreach (config('equipment.passport') as $key => $definition) {
            $rules['passport.'.$key] = $definition['rules'];
            $rules['passport_qualifiers.'.$key] = 'sometimes|array:state,precision';
            $rules['passport_qualifiers.'.$key.'.state'] = 'sometimes|in:unknown,not_applicable,not_present';
            $rules['passport_qualifiers.'.$key.'.precision'] = ($definition['type'] ?? '') === 'date' ? 'sometimes|in:day,year' : 'prohibited';
        }
        $rules['passport_qualifiers.name.state'] = 'sometimes|in:unknown';
        if (! $updating) {
            return $rules;
        }
        $rules = array_replace($rules, ['profile_code' => 'prohibited', 'version' => 'required|integer|min:1', 'status' => 'sometimes|in:active,archived', 'verification' => 'sometimes|array:'.implode(',', $keys)]);
        foreach ($keys as $key) {
            $rules['verification.'.$key] = 'sometimes|boolean';
        }

        return $rules + EquipmentStructure::rules() + EquipmentDiagnostics::rules() + EquipmentThickness::rules() + EquipmentFlowContacts::rules() + EquipmentFlowSelection::rules() + EquipmentOperatingScopes::rules() + VisualInspectionInputValidation::rules() + InspectionCategoryInputValidation::rules() + MechanismInputValidation::rules() + ComponentRbiInputValidation::rules();
    }
}
