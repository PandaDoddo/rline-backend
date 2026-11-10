<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

final class ThinningModifiers
{
    public function monitoring(array $activeMechanisms, ?string $system, mixed $velocity, ?array $material, array $tables): array
    {
        if ($velocity !== null && ((! is_int($velocity) && ! is_float($velocity)) || ! is_finite($velocity) || $velocity < 0)) {
            throw new InvalidArgumentException('Скорость среды должна быть конечной и неотрицательной.');
        }
        $systems = [];
        foreach ($tables['thinMonitoringSystems'] as $row) {
            $systems[$row[0]] = $row[1];
            $systems[$row[1]] = $row[1];
        }
        $code = $system === null ? null : ($systems[$system] ?? null);
        if (! $activeMechanisms || $code === 'none') {
            return ['value' => 1, 'missing' => [], 'source' => ['system' => $code, 'rows' => [], 'reason' => $activeMechanisms ? 'explicit_no_monitoring' : 'no_active_thinning']];
        }
        if ($code === null) {
            return ['value' => null, 'missing' => ['corrosion_monitoring'], 'source' => ['system' => null, 'rows' => []]];
        }
        $factors = [];
        foreach (array_unique($activeMechanisms) as $mechanism) {
            if (! is_string($mechanism) || $mechanism === '') {
                throw new InvalidArgumentException('Неверный идентификатор МД.');
            }
            $class = 'high';
            if ($velocity !== null && $mechanism === 'h2so4') {
                $subtype = $material['subtype'] ?? null;
                $limit = $subtype === null ? null : (str_contains($subtype, 'нержавеющая') ? 1.5 : (in_array($subtype, ['углеродистая', 'низколегированная'], true) ? 0.9 : 2.1));
                if ($limit !== null) {
                    $class = $velocity <= $limit ? 'low' : 'high';
                }
            } elseif ($velocity !== null && in_array($mechanism, ['sw', 'asw'], true)) {
                $class = $velocity <= 6.1 ? 'low' : 'high';
            }
            // The legacy/API input policy uses high velocity for the remaining mechanisms.
            $selected = null;
            foreach ($tables['thinMonitoringFactors'] as $index => $row) {
                if (in_array($mechanism, $row['ids'], true) && (! isset($row['vel']) || $row['vel'] === $class)) {
                    $selected = $index;
                    break;
                }
            }
            if ($selected === null) {
                $selected = array_key_last($tables['thinMonitoringFactors']);
            }
            $row = $tables['thinMonitoringFactors'][$selected];
            $value = $code === 'kpc' ? ($row['kpc'] ?? max($row['kp'], $row['er'])) : ($row[$code] ?? null);
            if ((! is_int($value) && ! is_float($value)) || ! is_finite($value) || $value <= 0) {
                throw new InvalidArgumentException('Недопустимый коэффициент FOM.');
            }
            $factors[] = ['mechanism' => $mechanism, 'row' => $selected, 'value' => $value, 'velocity_class' => $class, 'velocity_unknown' => $velocity === null];
        }

        return ['value' => min(array_column($factors, 'value')), 'missing' => [], 'source' => ['system' => $code, 'rows' => $factors, 'reason' => 'minimum_active_mechanisms']];
    }

    public function geometry(?string $typeCode, bool $sphericalVessel, ?string $seamType, mixed $weldEfficiency, array $tables): array
    {
        if ($weldEfficiency !== null && ((! is_int($weldEfficiency) && ! is_float($weldEfficiency)) || ! is_finite($weldEfficiency) || $weldEfficiency <= 0 || $weldEfficiency > 1)) {
            throw new InvalidArgumentException('Коэффициент сварного соединения должен быть больше 0 и не больше 1.');
        }
        $head = ['head', 'cap', 'flanged_cap', 'cover', 'bonnet', 'spectacle_blind'];
        $cylinder = ['shell', 'shell_course', 'pipe', 'nozzle', 'branch_pipe', 'elbow', 'return_bend', 'reducer', 'tee', 'boss', 'internal_coil', 'external_coil', 'coil_body', 'tube_bundle', 'tube_sheet', 'heat_exchange_tube', 'heat_exchange_section', 'tube_header', 'lens', 'lens_expansion_joint', 'bellows_expansion_joint', 'valve', 'valve_body'];
        $kind = $sphericalVessel && in_array($typeCode, ['shell', 'shell_course', 'head'], true) ? 'sph' : (in_array($typeCode, $head, true) ? 'head' : (in_array($typeCode, $cylinder, true) ? 'cyl' : null));
        $alpha = $kind === null ? null : (array_column($tables['thinAlpha'], 'a', 'k')[$kind] ?? null);
        $fixed = $seamType === 'seamless' ? 'seamless' : (in_array($typeCode, ['flange', 'boss'], true) ? 'solid_component' : null);
        $efficiency = $fixed !== null ? 1 : $weldEfficiency;
        $values = ['alpha' => $alpha, 'E' => $efficiency];

        return ['values' => $values, 'missing' => array_keys(array_filter($values, fn ($value) => $value === null)),
            'source' => ['alpha_kind' => $kind, 'weld_efficiency' => $fixed ?? ($weldEfficiency !== null ? 'explicit_component_value' : null)]];
    }

    public function pipeFactors(bool $pipeline, mixed $injection, mixed $deadLeg, mixed $componentInjection, mixed $componentDeadLeg): array
    {
        foreach ([$injection, $deadLeg, $componentInjection, $componentDeadLeg] as $flag) {
            if ($flag !== null && ! is_bool($flag)) {
                throw new InvalidArgumentException('Признак участка должен быть boolean или null.');
            }
        }
        $values = [];
        $missing = [];
        $invalid = [];
        foreach (['FIP' => [$injection, $componentInjection], 'FDL' => [$deadLeg, $componentDeadLeg]] as $key => [$global, $local]) {
            if (! $pipeline) {
                $values[$key] = 1;
            } elseif ($global === false && $local === true) {
                $values[$key] = null;
                $invalid[] = $key.'_contradiction';
            } elseif ($local === false || $global === false) {
                $values[$key] = 1;
            } elseif ($global === true && $local === true) {
                $values[$key] = 3;
            } else {
                $values[$key] = null;
                $missing[] = $key;
            }
        }

        return ['values' => $values, 'missing' => $missing, 'invalid' => $invalid];
    }
}
