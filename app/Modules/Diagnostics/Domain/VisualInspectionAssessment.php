<?php

namespace App\Modules\Diagnostics\Domain;

use App\Modules\Equipment\Domain\ComponentParameterScope;
use App\Modules\Equipment\Domain\ComponentTree;

/** Pure read assessment; unknown exposure never becomes a definite Ext/CUI classification. */
final class VisualInspectionAssessment
{
    public function evaluate(array $components, array $facts, array $flows, array $passport, array $scopes = []): array
    {
        $tree = new ComponentTree($components);
        $byId = array_column($components, null, 'id');
        $current = array_fill_keys($facts['current_element_ids'], true);
        $overrides = array_column($facts['insulation_overrides'], 'is_insulated', 'component_id');
        $effective = [];
        foreach ($flows['effective'] ?? [] as $entry) {
            if ($entry['side'] === 'insulation') {
                $effective[$entry['component_id']] = $entry;
            }
        }
        $contacts = array_column($flows['contacts'] ?? [], null, 'id');
        $ids = $current;
        foreach ($facts['stages'] as $stage) {
            foreach ($stage['observations'] as $observation) {
                $ids[$observation['component_id']] = true;
            }
        }
        $elements = [];
        $summaries = [];
        foreach ($ids as $id => $_) {
            if (! isset($byId[$id])) {
                continue;
            }
            $root = $tree->root($id);
            $scope = $root['is_coil'] ? $root['id'] : 'vessel';
            $source = 'unknown';
            $insulated = null;
            $review = false;
            $auto = null;
            $assignment = $effective[$id] ?? [];
            if (($assignment['mode'] ?? null) === 'none') {
                $auto = false;
            } elseif (($assignment['mode'] ?? null) === 'assign' && ($contact = $contacts[$assignment['contact_id']] ?? null) && $contact['flows']) {
                $auto = true;
                $review = count(array_filter($contact['flows'], fn ($flow) => ($flow['stale'] ?? false) || ($flow['flow_status'] ?? 'active') !== 'active')) > 0;
            }
            $protection = $passport;
            $parameterScope = ComponentParameterScope::resolve($tree, $id);
            if ($parameterScope['kind'] !== 'vessel') {
                // A separate scope must not borrow vessel protection parameters.
                $protection = [];
                foreach ($scopes as $candidate) {
                    if ($candidate['kind'] === $parameterScope['kind'] && $candidate['component_id'] === $parameterScope['component_id']) {
                        $protection = (array) $candidate['values'];
                        break;
                    }
                }
            }
            if ($auto === null && ! $review) {
                $auto = match ($protection['insulation_present'] ?? null) {
                    'присутствует' => true, 'отсутствует' => false, default => null
                };
                if ($auto !== null) {
                    $source = 'passport';
                }
            }
            if (array_key_exists($id, $overrides)) {
                $insulated = $overrides[$id];
                $source = 'manual';
                $review = false;
            } else {
                $insulated = $auto;
                if ($source !== 'passport') {
                    $source = $auto === null ? 'unknown' : 'flows';
                }
            }
            $coating = match ($protection['external_coating_present'] ?? null) {
                'присутствует' => true, 'отсутствует' => false, default => null
            };
            $nominal = $tree->value($id, 'nominal_thickness_mm');
            $elements[$id] = ['component_id' => $id, 'scope_id' => $scope, 'current_element' => isset($current[$id]), 'active' => $tree->active($id),
                'nominal_thickness_mm' => $nominal === null ? null : (float) $nominal, 'insulated' => $insulated,
                'auto_insulated' => $auto, 'insulation_source' => $source, 'insulation_requires_review' => $review, 'coating_applicable' => $coating];
            $summaries[$scope] ??= ['scope_id' => $scope, 'name' => $scope === 'vessel' ? 'Сосуд / основное оборудование' : $root['code'].' · '.$root['name'],
                'current_elements' => 0, 'observed_elements' => 0, 'corroded_elements' => 0, 'unknown_corrosion_elements' => 0, 'partial_elements' => 0, 'max_depth_mm' => null, 'max_depth_percent' => null];
            if (isset($current[$id]) && $tree->active($id)) {
                $summaries[$scope]['current_elements']++;
            }
        }
        $stages = $facts['stages'];
        usort($stages, fn ($a, $b) => strcmp($a['measured_on'] ?? $a['source_date'], $b['measured_on'] ?? $b['source_date']) ?: strcmp($a['id'], $b['id']));
        $previousDepth = [];
        $latest = [];
        $anomalies = [];
        $byStage = [];
        foreach ($stages as $stage) {
            $stage['date_anomaly'] = $stage['measured_on'] !== null && $stage['diagnostic_on'] !== null && $stage['measured_on'] > $stage['diagnostic_on'];
            if ($stage['date_anomaly']) {
                $anomalies[] = ['stage_id' => $stage['id'], 'component_id' => null, 'code' => 'date_after_diagnosis', 'message' => 'Дата ВИК позже даты диагностики.'];
            }
            foreach ($stage['observations'] as &$observation) {
                $id = $observation['component_id'];
                $element = $elements[$id] ?? null;
                if (! $element) {
                    continue;
                }
                $values = $observation['values'];
                $observed = $this->observed($values) || $stage['verified'];
                $observation['category'] = $observed ? (($values['partial'] ?? null) === true ? 'C' : 'B') : 'E';
                $observation['kind'] = $element['insulated'] === null ? null : ($element['insulated'] ? 'cui' : 'ext');
                $depth = $values['depth_mm'] ?? null;
                $nominal = $element['nominal_thickness_mm'];
                $observation['depth_percent'] = $depth !== null && $nominal !== null && $nominal > 0 ? $depth / $nominal * 100 : null;
                $messages = [];
                if ($depth !== null && $nominal !== null && $nominal > 0 && $depth >= $nominal) {
                    $messages['depth_reaches_nominal'] = 'Глубина поражения не меньше номинальной толщины.';
                }
                if (($values['corrosion_present'] ?? null) === false && $depth !== null && $depth > 0) {
                    $messages['depth_without_corrosion'] = 'Коррозия отмечена как отсутствующая при положительной глубине.';
                }
                if ($element['insulated'] === false && ($values['insulation_condition'] ?? null) !== null) {
                    $messages['condition_without_insulation'] = 'Состояние изоляции задано при отсутствии изоляции.';
                }
                if ($element['insulated'] === true && $observed && ($values['insulation_condition'] ?? null) === null) {
                    $messages['missing_insulation_condition'] = 'Для осмотренного элемента CUI не задано состояние изоляции.';
                }
                if ($depth !== null && $stage['measured_on'] !== null) {
                    if (isset($previousDepth[$id]) && $stage['measured_on'] > $previousDepth[$id]['date'] && $depth < $previousDepth[$id]['depth']) {
                        $messages['depth_decreased'] = 'Глубина поражения уменьшилась относительно предыдущего осмотра.';
                    }
                    $previousDepth[$id] = ['date' => $stage['measured_on'], 'depth' => $depth];
                }
                $observation['anomalies'] = [];
                foreach ($messages as $code => $message) {
                    $entry = ['stage_id' => $stage['id'], 'component_id' => $id, 'code' => $code, 'message' => $message];
                    $observation['anomalies'][] = $entry;
                    $anomalies[] = $entry;
                }
                if ($observed) {
                    $latest[$id] = $values;
                }
                if ($element['current_element'] && $element['active']) {
                    $summary = &$summaries[$element['scope_id']];
                    foreach (['max_depth_mm' => $depth, 'max_depth_percent' => $observation['depth_percent']] as $key => $value) {
                        if ($value !== null) {
                            $summary[$key] = $summary[$key] === null ? $value : max($summary[$key], $value);
                        }
                    }
                    unset($summary);
                }
            }
            unset($observation);
            // Checking a stage affirms the inspection, but does not invent corrosion values.
            if ($stage['verified']) {
                foreach ($elements as $id => $element) {
                    $latest[$id] ??= [];
                }
            }
            $byStage[$stage['id']] = $stage;
        }
        foreach ($latest as $id => $values) {
            $element = $elements[$id];
            if ($element['current_element'] && $element['active']) {
                $summary = &$summaries[$element['scope_id']];
                $summary['observed_elements']++;
                $summary['unknown_corrosion_elements'] += ($values['corrosion_present'] ?? null) === null ? 1 : 0;
                $summary['corroded_elements'] += ($values['corrosion_present'] ?? null) === true ? 1 : 0;
                $summary['partial_elements'] += ($values['partial'] ?? null) === true ? 1 : 0;
                unset($summary);
            }
        }

        $summaries = array_values($summaries);
        usort($summaries, fn ($a, $b) => ($a['scope_id'] === 'vessel' ? -1 : ($byId[$a['scope_id']]['position'] ?? 0)) <=> ($b['scope_id'] === 'vessel' ? -1 : ($byId[$b['scope_id']]['position'] ?? 0)) ?: strcmp($a['scope_id'], $b['scope_id']));

        return ['stages' => array_map(fn ($stage) => $byStage[$stage['id']], $facts['stages']), 'elements' => array_values($elements), 'scope_summaries' => $summaries, 'anomalies' => $anomalies];
    }

    private function observed(array $values): bool
    {
        foreach ($values as $key => $value) {
            if ($value !== null && ! in_array($key, ['notes', 'area_unit'], true)) {
                return true;
            }
        }

        return false;
    }
}
