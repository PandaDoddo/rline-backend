<?php

namespace App\Modules\Equipment\Domain;

use App\Modules\Diagnostics\Domain\NormativePeriodicity;
use DateTimeImmutable;
use DateTimeZone;

/** Pure calculation over measured element points. No demo values and no database access. */
class CorrosionCalculator
{
    public function calculate(array $passport, array $components, array $thickness, array $inspections, bool $vessel = true, ?array $plans = null): array
    {
        $elements = $this->elements($passport, $components, $thickness, $inspections, $plans);
        $byId = array_column($components, null, 'id');
        $tree = new ComponentTree($components);
        $cmlComponents = [];
        $mappedCmls = [];
        $owners = [];
        $scopeOwners = [];
        foreach ($thickness['cmls'] as $cml) {
            $component = $byId[$cml['component_id']] ?? null;
            $root = $component ? $tree->root($component['id']) : null;
            if (! $component || ! $root) {
                continue;
            }
            $cmlComponents[] = array_replace($component, ['id' => $cml['id'], 'parent_id' => null, 'code' => $cml['code'] ?? $cml['id'], 'is_coil' => false,
                'status' => $tree->active($component['id']) ? 'active' : 'removed',
                'nominal_thickness_mm' => $tree->value($component['id'], 'nominal_thickness_mm'),
                'rejection_passport_mm' => $tree->value($component['id'], 'rejection_passport_mm'),
                'rejection_epb_mm' => $tree->value($component['id'], 'rejection_epb_mm')]);
            $mappedCmls[] = array_replace($cml, ['component_id' => $cml['id']]);
            $owners[$cml['id']] = $component['id'];
            $scopeOwners[$cml['id']] = $root['is_coil'] ? $root['id'] : 'vessel';
        }
        $cmlResults = $this->elements($passport, $cmlComponents, ['cmls' => $mappedCmls, 'stages' => $thickness['stages']], $inspections, $plans);
        foreach ($cmlResults as &$cmlResult) {
            $cmlResult['element_id'] = $owners[$cmlResult['id']];
            $cmlResult['scope_id'] = $scopeOwners[$cmlResult['id']];
        }
        unset($cmlResult);
        $scopes = [['id' => 'vessel', 'name' => 'Сосуд / основное оборудование']];
        foreach ($components as $component) {
            if ($component['is_coil'] && $component['status'] === 'active') {
                $scopes[] = ['id' => $component['id'], 'name' => $component['name']];
            }
        }
        foreach ($scopes as &$scope) {
            $members = array_values(array_filter($elements, fn ($element) => $element['scope_id'] === $scope['id']));
            $scope['elements'] = count($members);
            $scope['rejected'] = count(array_filter($members, fn ($element) => ($element['latest']['status'] ?? null) === 'rejected'));
            $scope['warning'] = count(array_filter($members, fn ($element) => ($element['latest']['status'] ?? null) === 'warning'));
            $scope['life_warning'] = count(array_filter($members, fn ($element) => $element['life_warning']));
            $rates = [];
            $lives = [];
            $wear = [];
            $hasInfinite = false;
            foreach ($members as $element) {
                if ($element['latest']) {
                    foreach ($element['latest']['rates']['min'] as $value) {
                        if ($value !== null) {
                            $rates[] = $value;
                        }
                    }
                    foreach ($element['latest']['life']['min'] as $value) {
                        if (is_numeric($value)) {
                            $lives[] = $value;
                        }
                        if ($value === 'infinite') {
                            $hasInfinite = true;
                        }
                    }
                    if ($element['latest']['wear_percent']['min'] !== null) {
                        $wear[] = $element['latest']['wear_percent']['min'];
                    }
                }
            }
            $scope['max_rate_mm_year'] = $rates ? max($rates) : null;
            $scope['min_life_years'] = $lives ? min($lives) : ($hasInfinite ? 'infinite' : null);
            $scope['average_wear_percent'] = $wear ? array_sum($wear) / count($wear) : null;
        }
        unset($scope);

        return ['method' => 'element-thickness-v1', 'elements' => $elements, 'cmls' => $cmlResults, 'scopes' => $scopes, 'periodicity' => (new NormativePeriodicity)->history($passport, $elements, $vessel), 'anomalies' => (new MeasurementAnomalies)->detect($thickness, $inspections, $elements)];
    }

    private function elements(array $passport, array $components, array $thickness, array $inspections, ?array $plans = null): array
    {
        $byId = array_column($components, null, 'id');
        $tree = new ComponentTree($components);
        $inspectionById = array_column($inspections, null, 'id');
        $stages = array_values(array_filter($thickness['stages'], fn ($stage) => ! empty($stage['measured_on'])));
        usort($stages, fn ($a, $b) => strcmp($a['measured_on'], $b['measured_on']) ?: strcmp($a['id'], $b['id']));
        $year = isset($passport['commissioned_on']) ? substr($passport['commissioned_on'], 0, 4) : null;
        $baseDate = $year && preg_match('/^\d{4}$/D', $year) ? $year.'-01-01' : null;
        $cmlOwners = array_column($thickness['cmls'], 'component_id', 'id');
        $expectedByComponent = [];
        $unknownCount = [];
        foreach ($thickness['cmls'] as $cml) {
            $expectedByComponent[$cml['component_id']] = ($expectedByComponent[$cml['component_id']] ?? 0) + ($cml['point_count'] ?? 0);
            if ($cml['point_count'] === null) {
                $unknownCount[$cml['component_id']] = true;
            }
        }
        $measured = [];
        foreach ($stages as $stage) {
            foreach ($stage['measurements'] as $measurement) {
                $owner = $cmlOwners[$measurement['cml_id']] ?? null;
                if (! $owner) {
                    continue;
                }
                foreach ($measurement['points'] as $point) {
                    if ($point !== null) {
                        $value = (float) $point;
                        $summary = $measured[$stage['id']][$owner] ?? ['count' => 0, 'sum' => 0.0, 'min' => $value, 'max' => $value];
                        $summary['count']++;
                        $summary['sum'] += $value;
                        $summary['min'] = min($summary['min'], $value);
                        $summary['max'] = max($summary['max'], $value);
                        $measured[$stage['id']][$owner] = $summary;
                    }
                }
            }
        }
        $next = $plans === null
            ? array_values(array_filter($inspections, fn ($inspection) => ! empty($inspection['planned_on']) && empty($inspection['performed_on']) && in_array($inspection['kind'], ['ТО-1', 'ТО-2', 'ЭПБ'], true)))
            : array_values(array_filter($plans, fn ($plan) => ! empty($plan['planned_on']) && empty($plan['absorbed_by']) && in_array($plan['kind'], ['ТО-1', 'ТО-2', 'ЭПБ'], true)));
        usort($next, fn ($a, $b) => strcmp($a['planned_on'], $b['planned_on']));
        $nextDate = isset($next[0]) ? substr($next[0]['planned_on'], 0, 10) : null;
        $elements = [];
        foreach ($components as $component) {
            $root = $tree->root($component['id']);
            if (! $tree->active($component['id'])) {
                continue;
            }
            if (! isset($expectedByComponent[$component['id']])) {
                continue;
            }
            $nominal = $tree->value($component['id'], 'nominal_thickness_mm');
            $epb = $tree->value($component['id'], 'rejection_epb_mm');
            $pas = $tree->value($component['id'], 'rejection_passport_mm');
            $rejection = $epb ?? $pas;
            $series = [];
            $expected = $expectedByComponent[$component['id']];
            foreach ($stages as $stage) {
                $measurement = $measured[$stage['id']][$component['id']] ?? null;
                if (! $measurement) {
                    continue;
                }
                $summary = ['min' => $measurement['min'], 'ave' => $measurement['sum'] / $measurement['count'], 'max' => $measurement['max']];
                $previous = $series ? $series[array_key_last($series)] : null;
                $first = $series[0] ?? null;
                $rates = [];
                $lives = [];
                foreach (['min', 'ave'] as $basis) {
                    $rates[$basis] = [
                        'nominal' => $this->rate($nominal, $summary[$basis], $baseDate, $stage['measured_on']),
                        'first' => $first ? $this->rate($first['values'][$basis], $summary[$basis], $first['date'], $stage['measured_on']) : null,
                        'short' => $previous ? $this->rate($previous['values'][$basis], $summary[$basis], $previous['date'], $stage['measured_on']) : $this->rate($nominal, $summary[$basis], $baseDate, $stage['measured_on']),
                    ];
                    foreach ($rates[$basis] as $name => $rate) {
                        $lives[$basis][$name] = $name === 'first' && ! $first ? null : $this->life($summary[$basis], $rejection, $rate);
                    }
                }
                $status = $rejection === null ? 'unknown' : ($summary['min'] <= $rejection ? 'rejected' : ($summary['min'] <= 1.1 * $rejection ? 'warning' : 'ok'));
                $wear = [];
                foreach (['min', 'ave'] as $basis) {
                    $wear[$basis] = $nominal !== null && $rejection !== null && $nominal > $rejection ? max(0, min(100, ($nominal - $summary[$basis]) / ($nominal - $rejection) * 100)) : null;
                }
                $inspection = $inspectionById[$stage['inspection_id']] ?? null;
                $series[] = ['stage_id' => $stage['id'], 'date' => $stage['measured_on'], 'values' => $summary, 'complete' => ! isset($unknownCount[$component['id']]) && $measurement['count'] === $expected,
                    'rates' => $rates, 'life' => $lives, 'status' => $status, 'wear_percent' => $wear,
                    'date_anomaly' => $inspection && ! empty($inspection['performed_on']) && $stage['measured_on'] > substr($inspection['performed_on'], 0, 10)];
            }
            $latest = $series ? $series[array_key_last($series)] : null;

            $finiteLives = $latest ? array_filter([$latest['life']['min']['nominal'], $latest['life']['min']['first']], fn ($value) => is_numeric($value)) : [];
            $lifeWarning = $latest && $nextDate && $finiteLives ? $this->decimalDate($latest['date']) + min($finiteLives) < $this->decimalDate($nextDate) : false;
            $elements[] = ['id' => $component['id'], 'code' => $component['code'], 'name' => $component['name'], 'scope_id' => $root['is_coil'] ? $root['id'] : 'vessel',
                'nominal_mm' => $nominal, 'rejection_mm' => $rejection, 'rejection_source' => $epb !== null ? 'epb' : ($pas !== null ? 'passport' : null),
                'series' => $series, 'latest' => $latest, 'next_uzt_on' => $nextDate, 'life_warning' => (bool) $lifeWarning];
        }

        return $elements;
    }

    private function rate(?float $from, float $to, ?string $fromDate, string $toDate): ?float
    {
        if ($from === null || ! $fromDate) {
            return null;
        }
        $years = $this->decimalDate($toDate) - $this->decimalDate($fromDate);

        return $years > 0 ? ($from - $to) / $years : null;
    }

    private function life(float $actual, ?float $rejection, ?float $rate): float|string|null
    {
        if ($rejection === null) {
            return null;
        }
        if ($actual <= $rejection) {
            return 0.0;
        }
        if ($rate === null) {
            return null;
        }
        if ($rate <= 0) {
            return 'infinite';
        }

        return floor((($actual - $rejection) / $rate + 1e-12) * 10) / 10;
    }

    private function decimalDate(string $date): float
    {
        $value = new DateTimeImmutable(substr($date, 0, 10), new DateTimeZone('UTC'));
        $year = (int) $value->format('Y');
        $start = $value->setDate($year, 1, 1);
        $end = $start->modify('+1 year');

        return $year + ($value->getTimestamp() - $start->getTimestamp()) / ($end->getTimestamp() - $start->getTimestamp());
    }
}
