<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

/** Rebuild DF rates from element MIN values using the original RBI time convention. */
final class ThinningMeasurementInputs
{
    public const VERSION = 'df-measurement-inputs-v1';

    public function resolve(array $element, ?string $commissionedOn, string $analysisOn, mixed $projectRate): array
    {
        $analysis = RbiDate::decimal($analysisOn);
        $commissioned = $commissionedOn === null ? null : RbiDate::decimal($commissionedOn);
        $nominal = $element['nominal_mm'] ?? null;
        $rejection = $element['rejection_mm'] ?? null;
        foreach ([$nominal, $rejection, $projectRate] as $value) {
            $this->number($value);
        }
        $series = [];
        $warnings = [];
        foreach ($element['series'] ?? [] as $row) {
            if (empty($row['date'])) {
                $warnings[] = ['code' => 'measurement_date_unknown', 'stage_id' => $row['stage_id'] ?? null];

                continue;
            }
            $date = RbiDate::decimal($row['date']);
            if ($row['date'] > $analysisOn) {
                $warnings[] = ['code' => 'measurement_after_analysis', 'stage_id' => $row['stage_id'] ?? null];

                continue;
            }
            $minimum = $row['values']['min'] ?? null;
            $this->number($minimum);
            if ($minimum === null) {
                continue;
            }
            if ($minimum < 0) {
                throw new InvalidArgumentException('Измеренная толщина не может быть отрицательной.');
            }
            $series[] = ['stage_id' => $row['stage_id'] ?? null, 'date' => $row['date'], 'time' => $date, 'minimum' => $minimum];
            if (($row['complete'] ?? null) === false) {
                $warnings[] = ['code' => 'incomplete_element_measurement', 'stage_id' => $row['stage_id'] ?? null];
            }
        }
        usort($series, fn ($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['stage_id'] ?? '', $b['stage_id'] ?? ''));
        $conflictingDates = [];
        foreach ($series as $index => $sample) {
            if ($index > 0 && $sample['date'] === $series[$index - 1]['date'] && $sample['minimum'] != $series[$index - 1]['minimum']) {
                $conflictingDates[$sample['date']] = true;
            }
        }
        $last = $series ? $series[array_key_last($series)] : null;
        $rates = ['nominal' => null, 'first' => null, 'short' => null];
        if ($last !== null) {
            $first = $series[0];
            $previous = count($series) > 1 ? $series[count($series) - 2] : null;
            if ($commissioned !== null && $nominal !== null && $last['time'] > $commissioned) {
                $rates['nominal'] = ($nominal - $last['minimum']) / ($last['time'] - $commissioned);
            }
            if (count($series) > 1 && $last['time'] > $first['time']) {
                $rates['first'] = ($first['minimum'] - $last['minimum']) / ($last['time'] - $first['time']);
            }
            $rates['short'] = $previous === null ? $rates['nominal'] : ($last['time'] > $previous['time'] ? ($previous['minimum'] - $last['minimum']) / ($last['time'] - $previous['time']) : null);
        }
        $available = array_filter($rates, fn ($value) => $value !== null);
        foreach ($available as $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Переполнение скорости коррозии DF.');
            }
        }
        $rate = $available ? max($available) : null;
        $measured = $rate !== null;
        $input = ['tAn' => $analysis, 'tIn' => $commissioned, 'tbm' => $nominal, 'tmin' => $rejection, 'CrM' => $rate,
            'trdiM' => $measured ? $last['minimum'] : null, 'tLast' => $measured ? $last['time'] : null, 'CrT' => $projectRate];
        $missing = [];
        foreach (['tIn', 'tbm', 'tmin'] as $key) {
            if ($input[$key] === null) {
                $missing[] = $key;
            }
        }
        if (! $measured && $projectRate === null) {
            $missing[] = 'corrosion_rate';
        }
        $invalid = [];
        if ($conflictingDates) {
            $invalid[] = 'conflicting_measurements_same_day';
        }
        if ($commissioned !== null && $analysis < $commissioned) {
            $invalid[] = 'tIn_after_analysis';
        }
        if ($nominal !== null && $nominal <= 0) {
            $invalid[] = 'tbm';
        }
        if ($rejection !== null && $rejection < 0) {
            $invalid[] = 'tmin';
        }
        if ($projectRate !== null && $projectRate < 0) {
            $invalid[] = 'CrT';
        }
        if ($measured && $last['minimum'] <= 0) {
            $invalid[] = 'trdiM';
        }
        if ($last && ! $measured) {
            $warnings[] = ['code' => 'measured_rate_unavailable', 'stage_id' => $last['stage_id']];
        }

        return ['method' => self::VERSION, 'values' => $input, 'complete' => ! $missing && ! $invalid, 'missing' => $missing, 'invalid' => $invalid, 'warnings' => $warnings,
            'source' => ['time_convention' => RbiDate::VERSION, 'commissioned_on' => $commissionedOn, 'analysis_on' => $analysisOn,
                'last_measurement' => $last, 'measurement_count' => count($series), 'rates' => $rates, 'rate_source' => $measured ? 'measured_element_minimum' : ($projectRate !== null ? 'project' : null),
                'selected_rate_kind' => $measured ? array_search($rate, $available, true) : null, 'rejection_source' => $element['rejection_source'] ?? null,
                'conflicting_measurement_dates' => array_keys($conflictingDates)]];
    }

    private function number(mixed $value): void
    {
        if ($value !== null && ((! is_int($value) && ! is_float($value)) || ! is_finite($value))) {
            throw new InvalidArgumentException('Ожидалось конечное число или null во входах толщинометрии DF.');
        }
    }
}
