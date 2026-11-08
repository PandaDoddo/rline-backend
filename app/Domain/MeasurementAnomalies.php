<?php

namespace App\Domain;

/** Signals for review, never corrections or automatic replacement of measured values. */
class MeasurementAnomalies
{
    public function detect(array $thickness, array $inspections, array $elements): array
    {
        $byInspection = array_column($inspections, null, 'id');
        $byElement = array_column($elements, null, 'id');
        $cmls = array_column($thickness['cmls'], null, 'id');
        $stages = $thickness['stages'];
        usort($stages, fn ($a, $b) => strcmp($a['measured_on'] ?? '', $b['measured_on'] ?? '') ?: strcmp($a['id'], $b['id']));
        $previous = [];
        $anomalies = [];
        $add = function (string $type, string $level, string $stage, ?string $component, ?string $cml, ?int $point, string $message) use (&$anomalies) {
            $anomalies[] = ['type' => $type, 'level' => $level, 'stage_id' => $stage, 'component_id' => $component, 'cml_id' => $cml, 'point' => $point, 'message' => $message];
        };
        foreach ($stages as $stage) {
            $inspection = $byInspection[$stage['inspection_id']] ?? null;
            if (! empty($stage['measured_on']) && ! empty($inspection['performed_on']) && $stage['measured_on'] > substr($inspection['performed_on'], 0, 10)) {
                $add('measurement_after_inspection', 'stage', $stage['id'], null, null, null, 'Дата замера позднее даты диагностики');
            }
            $byOwner = [];
            foreach ($stage['measurements'] as $measurement) {
                $cml = $cmls[$measurement['cml_id']] ?? null;
                if (! $cml || ! isset($byElement[$cml['component_id']])) {
                    continue;
                }
                $owner = $cml['component_id'];
                $nominal = $byElement[$owner]['nominal_mm'];
                $values = array_values(array_filter($measurement['points'], fn ($value) => $value !== null));
                sort($values, SORT_NUMERIC);
                $count = count($values);
                $median = $count ? ($count % 2 ? $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2) : null;
                foreach ($measurement['points'] as $index => $value) {
                    if ($value !== null) {
                        $byOwner[$owner][$cml['id'].'|'.$index] = (float) $value;
                        if ($count >= 3 && $nominal !== null && $nominal > 0 && abs($value - $median) > 0.2 * $nominal) {
                            $below = $value < $median;
                            $add($below ? 'additional_control' : 'probable_recording_error', 'point', $stage['id'], $owner, $cml['id'], $index + 1, $below ? 'требуется доп. контроль' : 'вероятная ошибка записи');
                        }
                    }
                }
            }
            if (empty($stage['measured_on'])) {
                continue;
            }
            foreach ($byOwner as $owner => $points) {
                $old = $previous[$owner] ?? null;
                $element = $byElement[$owner];
                $entry = null;
                foreach ($element['series'] as $candidate) {
                    if ($candidate['stage_id'] === $stage['id']) {
                        $entry = $candidate;
                        break;
                    }
                }
                if ($old) {
                    $pairs = array_intersect_key($points, $old['points']);
                    if (count($pairs) >= 3) {
                        $differences = [];
                        foreach ($pairs as $key => $value) {
                            $differences[] = $value - $old['points'][$key];
                        }
                        $allPaired = count($pairs) === count($points) && count($pairs) === count($old['points']);
                        if ($allPaired && max($differences) - min($differences) < 1e-9) {
                            $add('probable_copy', 'element', $stage['id'], $owner, null, null, 'вероятный copypast');
                        }
                        $allGrew = $allPaired && min($differences) > 0;
                        $oldValues = array_values($old['points']);
                        $newValues = array_values($points);
                        $oldAverage = array_sum($oldValues) / count($oldValues);
                        $newAverage = array_sum($newValues) / count($newValues);
                        if ($allGrew && min($newValues) - min($oldValues) > 0.1 && max($newValues) - max($oldValues) > 0.1 && $newAverage - $oldAverage > 0.1) {
                            $add('probable_instrument_setting', 'element', $stage['id'], $owner, null, null, 'вероятная ошибка настройки прибора');
                        }
                        if ($allGrew && $element['nominal_mm'] !== null && $newAverage - $oldAverage > 0.1 * $element['nominal_mm']) {
                            $add('probable_element_replacement', 'element', $stage['id'], $owner, null, null, 'вероятно незафиксированная замена элемента');
                        }
                    }
                }
                if ($entry && $entry['rates']['ave']['nominal'] !== null && $entry['rates']['ave']['nominal'] > 0 && $entry['rates']['ave']['short'] !== null && $entry['rates']['ave']['short'] >= 3 * $entry['rates']['ave']['nominal']) {
                    $add('sudden_thinning', 'element', $stage['id'], $owner, null, null, 'резкое утонение — проверьте замер');
                }
                $previous[$owner] = ['points' => $points];
            }
        }

        return $anomalies;
    }
}
