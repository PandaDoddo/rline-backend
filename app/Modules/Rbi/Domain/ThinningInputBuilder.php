<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

/** Compose numerical DF input plus provenance; this service does not read models or save accepted runs. */
final class ThinningInputBuilder
{
    public const VERSION = 'df-thin-input-builder-v1';

    public function build(array $source, array $referenceSnapshot, array $inputSnapshot): array
    {
        $state = $source['applicability']['state'] ?? null;
        if (! in_array($state, ['active', 'potential', 'inactive'], true)) {
            throw new InvalidArgumentException('Требуется явная применимость DF утонения.');
        }
        if ($state === 'inactive') {
            return ['method' => self::VERSION, 'ready' => true, 'excluded' => true, 'input' => null, 'missing' => [], 'invalid' => [], 'assumptions' => [],
                'source' => ['applicability' => $source['applicability']], 'calculation' => null];
        }
        foreach (['pipeline', 'spherical_vessel'] as $flag) {
            if (! isset($source[$flag]) || ! is_bool($source[$flag])) {
                throw new InvalidArgumentException('Требуется явный классификатор оборудования: '.$flag);
            }
        }
        $passport = $source['passport'];
        $component = $source['component'];
        $settings = $source['component_rbi'] ?? [];
        $material = $source['material'] ?? null;
        $tables = $referenceSnapshot['tables'];
        $inputTables = $inputSnapshot['tables'];
        $modifiers = new ThinningModifiers;
        $measurements = (new ThinningMeasurementInputs)->resolve($source['measurement'], $source['commissioned_on'] ?? null, $source['analysis_on'], $passport['design_corrosion_mm_year'] ?? null);
        $temperature = $this->number($passport['temperature_design_c'] ?? null);
        $strength = (new MaterialStrengthInputs)->resolve($material, $temperature, $source['pipeline']);
        $geometry = $modifiers->geometry($component['type_code'] ?? null, $source['spherical_vessel'], $component['seam_type'] ?? null, $settings['weld_efficiency'] ?? null, $inputTables);
        $active = array_column(array_filter($source['applicability']['sources'] ?? [], fn ($row) => $row['state'] === 'active'), 'mechanism');
        if ($state === 'active' && ! $active) {
            throw new InvalidArgumentException('Активное утонение должно иметь механизм-основание.');
        }
        $monitoring = $modifiers->monitoring($active, $passport['corrosion_monitoring'] ?? null, $this->number($passport['flow_velocity_m_s'] ?? null), $material, $inputTables);
        $pipes = $modifiers->pipeFactors($source['pipeline'], $this->flag($passport['injection_point'] ?? null), $this->flag($passport['dead_leg'] ?? null), $settings['injection_point'] ?? null, $settings['dead_leg'] ?? null);
        $pressure = $this->number($passport['pressure_design_mpa'] ?? null);
        $diameter = $this->number($component['diameter_mm'] ?? null);
        $missing = [...$measurements['missing'], ...$strength['missing'], ...$geometry['missing'], ...$monitoring['missing'], ...$pipes['missing']];
        $invalid = [...$measurements['invalid'], ...$pipes['invalid']];
        if ($state === 'potential') {
            $missing[] = 'mechanism_applicability';
        }
        if ($temperature === null) {
            $missing[] = 'temperature_design_c';
        }
        if ($pressure === null) {
            $missing[] = 'P';
        } elseif ($pressure < 0) {
            $invalid[] = 'P';
        }
        if ($diameter === null) {
            $missing[] = 'D';
        } elseif ($diameter <= 0) {
            $invalid[] = 'D';
        }
        $counts = [];
        foreach (['A', 'B', 'C', 'D'] as $category) {
            $value = $source['inspection_counts'][$category] ?? null;
            if ($value === null) {
                $missing[] = 'N.'.$category;
            } elseif (! is_int($value) || $value < 0 || $value > 100000) {
                $invalid[] = 'N.'.$category;
            } else {
                $counts[$category] = $value;
            }
        }
        $assumptions = [];
        $confidence = $source['confidence'] ?? null;
        if ($confidence === null) {
            $confidence = 'med';
            $assumptions[] = ['code' => 'prior_confidence_default_med', 'value' => 'med'];
        }
        if (! in_array($confidence, ['low', 'med', 'high'], true)) {
            throw new InvalidArgumentException('Неизвестный уровень предварительной уверенности.');
        }
        $priors = array_column($tables['thinPriors'], $confidence);
        $likelihoods = [];
        foreach (['A', 'B', 'C', 'D'] as $category) {
            $likelihoods[$category] = array_column($tables['thinLikelihoods'], $category);
        }
        // Calculated minimum thickness is a separate deferred feature. Do not substitute passport rejection for tc.
        $input = [...$measurements['values'], ...$strength['values'], ...$geometry['values'], ...$pipes['values'], 'FOM' => $monitoring['value'], 'P' => $pressure, 'D' => $diameter,
            'tc' => null, 'N' => $counts, 'prp' => $priors, 'cop' => $likelihoods];
        $missing = array_values(array_unique($missing));
        $invalid = array_values(array_unique($invalid));
        $calculation = ! $missing && ! $invalid ? (new ThinningDamageFactor)->calculate($input) : null;

        return ['method' => self::VERSION, 'ready' => ! $missing && ! $invalid && ($calculation['ready'] ?? false), 'excluded' => false, 'input' => $input,
            'missing' => array_values(array_unique([...$missing, ...($calculation['missing'] ?? [])])), 'invalid' => $invalid, 'assumptions' => $assumptions,
            'source' => ['applicability' => $source['applicability'], 'measurements' => $measurements['source'], 'strength' => $strength['source'], 'geometry' => $geometry['source'], 'monitoring' => $monitoring['source'],
                'confidence' => $confidence, 'reference_version' => $referenceSnapshot['version'], 'input_reference_version' => $inputSnapshot['version']], 'calculation' => $calculation];
    }

    private function number(mixed $value): int|float|null
    {
        if ($value !== null && ((! is_int($value) && ! is_float($value)) || ! is_finite($value))) {
            throw new InvalidArgumentException('Источник DF должен содержать нормализованное конечное число или null.');
        }

        return $value;
    }

    private function flag(mixed $value): ?bool
    {
        return match ($value) {
            null => null, 'да', true => true, 'нет', false => false, default => throw new InvalidArgumentException('Неверный признак паспорта DF.')
        };
    }
}
