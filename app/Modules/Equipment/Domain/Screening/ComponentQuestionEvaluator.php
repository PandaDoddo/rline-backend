<?php

namespace App\Modules\Equipment\Domain\Screening;

use App\Modules\ProcessFlows\Domain\FlowScreening;
use InvalidArgumentException;

/** Explicit rule codes from a frozen schema; runtime logic does not match translated labels. */
final class ComponentQuestionEvaluator
{
    public function __construct(private readonly FlowScreening $numbers = new FlowScreening) {}

    public function evaluate(array $question, array $context, ?array $flow): array
    {
        $rule = $question['rule'];
        if ($rule === 'flow') {
            return $this->flow($question, $flow, $context);
        }
        if ($rule === 'temperature' || $rule === 'pressure') {
            $key = $rule === 'temperature' ? 'temperature_max_c' : 'pressure_operating_mpa';
            $value = $context['parameters'][$key] ?? null;
            $source = 'passport.'.$key;
            // AGENTS.md: the card/scope wins; missing T/P may use this explicitly assigned stream.
            if ($value === null && ($flow['usable'] ?? true) && isset($flow['parameters'][$key])) {
                $value = $flow['parameters'][$key];
                $source = 'flow.'.$key;
            }

            return $this->number($question, $value, $flow, $source);
        }
        if (str_starts_with($rule, 'material_')) {
            return $this->material($rule, $context['material'] ?? null);
        }
        if (in_array($rule, ['insulated', 'uninsulated', 'soil_contact'], true) && ($context['external_source_usable'] ?? true) === false) {
            return $this->result('unknown', null, 'physical_contacts.require_review');
        }
        if (in_array($rule, ['insulated', 'uninsulated', 'soil_contact'], true) && ($context['external_process'] ?? null) === true) {
            return $this->result('inactive', false, 'physical_contacts.external_process');
        }
        if ($rule === 'insulated' || $rule === 'uninsulated') {
            $value = $context['insulated'] ?? null;

            return $this->boolean($value === null ? null : $value === ($rule === 'insulated'), 'physical_contacts.insulation');
        }
        if ($rule === 'soil_contact' || $rule === 'tank_floor' || $rule === 'pipeline') {
            return $this->boolean($context[$rule] ?? null, 'component.'.$rule);
        }
        if ($rule === 'vibration') {
            if (($context['pipeline'] ?? null) === false || ($context['vibration_excluded'] ?? null) === true) {
                return $this->boolean(false, 'equipment.vibration');
            }

            return $this->boolean($context['vibration_present'] ?? null, 'equipment.vibration');
        }
        if ($rule === 'brittleness_temperature') {
            $parameters = $context['parameters'] ?? [];
            $minimum = $parameters['temperature_mdmt_c'] ?? $parameters['temperature_mat_c'] ?? null;
            $working = array_filter([$parameters['temperature_min_c'] ?? null, $parameters['temperature_upset_c'] ?? null, $parameters['temperature_shutdown_c'] ?? null], fn ($value) => $value !== null);
            if ($minimum === null || ! $working) {
                return $this->result('unknown', null, 'passport.minimum_temperature');
            }
            $this->numeric($minimum);
            foreach ($working as $value) {
                $this->numeric($value);
            }

            return $this->boolean($minimum >= min($working), 'passport.minimum_temperature');
        }
        throw new InvalidArgumentException('Неизвестное правило компонентного скрининга.');
    }

    private function material(string $rule, ?array $material): array
    {
        if (! $material) {
            return $this->result('unknown', null, 'material');
        }
        $sub = $this->knownText($material['subtype'] ?? null);
        $kind = $this->knownText($material['kind'] ?? null);
        $group = $this->knownText($material['rbi_group'] ?? null);
        $value = match ($rule) {
            'material_carbon_low_alloy' => $sub === null ? null : in_array($sub, ['углеродистая', 'низколегированная'], true),
            'material_carbon' => $sub === null ? null : $sub === 'углеродистая',
            'material_austenitic_or_nickel' => in_array($kind, ['сплав ni', 'никелевый сплав'], true) ? true : ($sub === null ? null : in_array($sub, ['аустенитная нержавеющая', 'дуплексная нержавеющая'], true)),
            'material_austenitic' => $sub === null ? null : in_array($sub, ['аустенитная нержавеющая', 'дуплексная нержавеющая'], true),
            'material_cr_mo' => $group === null ? null : (bool) preg_match('/^(углеродистая|c-0\.5mo|[\d.]+cr-[\d.]+mo)$/D', $group),
            'material_low_cr_mo' => $group === null ? null : in_array($group, ['1cr-0.5mo', '1.25cr-0.5mo', '2.25cr-1mo', '3cr-1mo'], true),
            'material_cr_co2' => $this->criterion($material['rbi_crCO2'] ?? null, '< 13% Cr'),
            'material_cr_885' => $this->criterion($material['rbi_cr885'] ?? null, '> 12% Cr'),
            default => throw new InvalidArgumentException('Неизвестный критерий материала.'),
        };

        return $this->boolean($value, 'steel_grade');
    }

    private function criterion(mixed $value, string $affirmative): ?bool
    {
        $value = $this->knownText($value);
        if ($value === null) {
            return null;
        }
        if ($value === 'не применимо') {
            return false;
        }

        // These are catalog classification choices, not inferred chemical composition.
        return $value === mb_strtolower($affirmative) ? true : null;
    }

    private function knownText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = mb_strtolower(trim($value));

        return in_array($value, ['', '—', 'нет данных', 'не установлено', 'не известно', 'неизвестно'], true) ? null : $value;
    }

    private function flow(array $question, ?array $flow, array $context): array
    {
        if (! $flow || ($flow['usable'] ?? true) === false) {
            return $this->result('unknown', null, 'flow.unavailable');
        }
        $values = $flow['screening']['values'] ?? [];
        $conditions = [];
        foreach ($question['conditions'] as $condition) {
            $field = $condition['field'];
            $value = $values[$field] ?? null;
            if ($condition['operator'] === 'yes') {
                $result = $this->boolean($value, 'flow.'.$field);
            } elseif ($field === 'pH2' && ($values['h2'] ?? null) !== true) {
                $result = $this->result(($values['h2'] ?? null) === false ? 'inactive' : 'unknown', null, 'flow.h2');
            } elseif ($field === 'pH2' && $value === null && ($context['hydrogen_partial_pressure_mpa'] ?? null) !== null) {
                $result = $this->number($question, $context['hydrogen_partial_pressure_mpa'], $flow, 'equipment.hydrogen_partial_pressure_mpa', $condition);
            } elseif ($field === 'cl' && ($values['clOn'] ?? null) !== true && ! array_key_exists('cl', $values)) {
                $result = $this->result(($values['clOn'] ?? null) === false ? $this->numbers->compare($condition['operator'], 0, $this->threshold($question, $condition, $flow)['threshold'], $this->threshold($question, $condition, $flow)['near']) : 'unknown', ($values['clOn'] ?? null) === false ? 0 : null, 'flow.clOn');
            } else {
                $result = $this->number($question, $field === 'cl' && ($values['clOn'] ?? null) === false ? 0 : $value, $flow, 'flow.'.$field, $condition);
            }
            $conditions[] = $result;
        }

        return ['status' => self::worst(array_column($conditions, 'status')), 'value' => null, 'source' => 'flow', 'conditions' => $conditions];
    }

    private function number(array $question, mixed $value, ?array $flow, string $source, ?array $condition = null): array
    {
        $condition ??= $question['conditions'][0];
        $this->numeric($value);
        $bounds = $this->threshold($question, $condition, $flow);

        return [...$this->result($this->numbers->compare($condition['operator'], $value, $bounds['threshold'], $bounds['near']), $value, $source), ...$bounds];
    }

    private function threshold(array $question, array $condition, ?array $flow): array
    {
        $override = ($flow['usable'] ?? true) === false ? [] : ($flow['screening']['thresholds'][$question['id']] ?? []);
        $threshold = $override['threshold'] ?? $condition['threshold'];
        $near = $override['near'] ?? $condition['near'];
        $size = $condition['operator'] === 'in' ? 2 : 1;
        if (! in_array($condition['operator'], ['>', '<', 'in'], true) || ! is_array($threshold) || ! array_is_list($threshold) || count($threshold) !== $size || ! is_array($near) || ! array_is_list($near) || count($near) > $size) {
            throw new InvalidArgumentException('Некорректная форма порога скрининга.');
        }
        foreach ([...$threshold, ...$near] as $value) {
            if ($value === null) {
                throw new InvalidArgumentException('Граница порога не может быть неизвестной.');
            } $this->numeric($value);
        }
        if ($size === 2 && $threshold[0] > $threshold[1] || array_filter($near, fn ($value) => $value < 0)) {
            throw new InvalidArgumentException('Проверьте порядок границ и неотрицательную зону внимания.');
        }

        return ['threshold' => $threshold, 'near' => $near];
    }

    private function numeric(mixed $value): void
    {
        if ($value !== null && ((! is_int($value) && ! is_float($value)) || ! is_finite($value))) {
            throw new InvalidArgumentException('Числовой ввод скрининга должен быть конечным числом либо неизвестным.');
        }
    }

    private function boolean(mixed $value, string $source): array
    {
        if ($value !== null && ! is_bool($value)) {
            throw new InvalidArgumentException('Булев ввод скрининга должен быть true / false / null.');
        }

        return $this->result($value === null ? 'unknown' : ($value ? 'active' : 'inactive'), $value, $source);
    }

    private function result(string $status, mixed $value, string $source): array
    {
        return ['status' => $status, 'value' => $value, 'source' => $source];
    }

    public static function worst(array $states): string
    {
        foreach (['inactive', 'unknown', 'attention'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return 'active';
    }

    public static function best(array $states): string
    {
        foreach (['active', 'attention', 'unknown'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return $states ? 'inactive' : 'unknown';
    }
}
