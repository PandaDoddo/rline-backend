<?php

namespace App\Modules\Diagnostics\Domain;

/** Sparse read projection: inspection quality is separate from mechanism applicability. */
final class InspectionCategoryAssessment
{
    public function evaluate(array $facts, array $thickness, array $diagnostics, array $visual, bool $pipeline, array $activity = []): array
    {
        $rules = new InspectionCategoryRules;
        $diagnostics = array_column($diagnostics, null, 'id');
        $elements = array_column($visual['elements'] ?? [], null, 'component_id');
        $cmls = array_column($thickness['cmls'] ?? [], null, 'id');
        $totals = [];
        foreach ($cmls as $cml) {
            $totals[$cml['component_id']] = ($totals[$cml['component_id']] ?? 0) + 1;
        }
        $coverage = [];
        foreach ($thickness['stages'] ?? [] as $stage) {
            $date = $stage['inspection_id'] ? ($diagnostics[$stage['inspection_id']]['performed_on'] ?? null) : $stage['measured_on'];
            if (! $date) {
                continue;
            }
            foreach ($stage['measurements'] as $measurement) {
                if (! isset($cmls[$measurement['cml_id']])) {
                    continue;
                }
                // A measured zero is a measurement; no requirement that all points are filled.
                if (! array_filter($measurement['points'], fn ($value) => $value !== null)) {
                    continue;
                }
                $owner = $cmls[$measurement['cml_id']]['component_id'];
                $coverage[$date][$owner][$measurement['cml_id']] = true;
            }
        }
        $visualByDate = array_column($visual['stages'] ?? [], null, 'source_date');
        $rows = [];
        foreach ($elements as $id => $element) {
            if (! $element['current_element']) {
                continue;
            }
            $groups = [];
            foreach (InspectionCategorySchema::groups() as $group => $_) {
                $known = $activity[$id][$group] ?? null;
                if (in_array($group, ['ext', 'cui'], true) && $known === true) {
                    $known = $element['insulated'] === null ? null : $element['insulated'] === ($group === 'cui');
                }
                $groups[$group] = ['applicability' => ! $element['active'] ? 'inactive' : ($known === true ? 'active' : ($known === false ? 'inactive' : 'unknown')), 'counts' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0]];
            }
            $rows[$id] = ['component_id' => $id, 'scope_id' => $element['scope_id'], 'active' => $element['active'], 'insulated' => $element['insulated'], 'cml_count' => $totals[$id] ?? 0, 'groups' => $groups];
        }
        $stages = [];
        foreach ($facts['stages'] as $stage) {
            $group = $stage['group_code'];
            $date = $stage['source_date'];
            $internal = in_array($stage['diagnostic_kind'], ['ТО-1', 'ТО-2', 'ЭПБ'], true);
            $vik = $visualByDate[$date] ?? null;
            $default = $group === 'thin' ? $rules->thinning($pipeline, $internal, 0, 0) : (in_array($group, ['ext', 'cui'], true) ? ($vik && $vik['verified'] ? 'B' : 'E') : 'E');
            $computed = [];
            if ($group === 'thin') {
                foreach ($coverage[$date] ?? [] as $id => $measured) {
                    if (! isset($rows[$id])) {
                        continue;
                    }
                    $computed[$id] = ['component_id' => $id, 'category' => $rules->thinning($pipeline, $internal, $totals[$id] ?? 0, count($measured)), 'measured_cmls' => count($measured)];
                }
            }
            if (in_array($group, ['ext', 'cui'], true) && $vik) {
                foreach ($vik['observations'] as $observation) {
                    $computed[$observation['component_id']] = ['component_id' => $observation['component_id'], 'category' => $rules->visual($observation['values'], $vik['verified']), 'measured_cmls' => null];
                }
            }
            $overrides = array_column($stage['observations'], null, 'component_id');
            foreach ($rows as $id => &$row) {
                if ($row['groups'][$group]['applicability'] !== 'active') {
                    continue;
                }
                // Ext and CUI do not share an inspection count for the same element.
                if (in_array($group, ['ext', 'cui'], true) && ($row['insulated'] === null || $row['insulated'] !== ($group === 'cui'))) {
                    continue;
                }
                $override = $overrides[$id]['category'] ?? null;
                $category = $override === 'none' ? null : ($override ?? $computed[$id]['category'] ?? $default);
                if ($category !== null && array_key_exists($category, $row['groups'][$group]['counts'])) {
                    $row['groups'][$group]['counts'][$category]++;
                }
            }
            unset($row);
            $stages[] = [...$stage, 'automatic_category' => $default, 'automatic_observations' => array_values($computed)];
        }

        return ['stages' => $stages, 'elements' => array_values($rows), 'mechanisms_ready' => count($rows) > 0 && count(array_filter($rows, fn ($row) => count(array_filter($row['groups'], fn ($group) => $group['applicability'] === 'unknown')) > 0)) === 0];
    }
}
