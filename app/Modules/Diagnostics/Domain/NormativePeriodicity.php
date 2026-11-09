<?php

namespace App\Modules\Diagnostics\Domain;

class NormativePeriodicity
{
    public function calculate(?string $ntd, ?string $registration, ?float $long, ?float $short, ?float $design, bool $vessel = true): array
    {
        $speeds = array_filter([$long, $short, $design], fn ($value) => $value !== null);
        $accepted = $speeds ? max($speeds) : null;
        $result = ['ntd' => $ntd, 'registration' => $registration, 'applicable' => false, 'message' => null,
            'long_speed' => $long, 'short_speed' => $short, 'design_speed' => $design, 'accepted_speed' => $accepted,
            'otn_years' => null, 'rtn_years' => null, 'hydro_years' => null, 'frequency_increased' => false];
        if (! $vessel || ! in_array($ntd, ['ФНП ОРПД', 'ИТНЭ-93', 'РУА-93'], true)) {
            $result['message'] = 'Нормативная периодичность для этого профиля не применяется.';

            return $result;
        }
        $result['applicable'] = true;
        if (! in_array($registration, ['РТН', 'ОТН'], true)) {
            $result['message'] = 'Укажите место регистрации в паспорте.';

            return $result;
        }
        if ($ntd !== 'ФНП ОРПД' && $registration !== 'ОТН') {
            $result['message'] = 'Для ИТНЭ-93 / РУА-93 требуется уточнить основание регистрации.';

            return $result;
        }
        if ($accepted === null) {
            $result['message'] = 'определите скорость коррозии';

            return $result;
        }
        if ($ntd === 'ФНП ОРПД') {
            $result['otn_years'] = $accepted <= 0.1 ? 2 : 1;
            $result['rtn_years'] = $registration === 'РТН' ? 4 : null;
            $result['hydro_years'] = 8;
        } else {
            $result['otn_years'] = $accepted <= 0.1 ? 6 : ($accepted <= 0.3 ? 2 : 1);
            $result['hydro_years'] = $accepted <= 0.1 ? 12 : 8;
        }
        $result['frequency_increased'] = $accepted > 0.1;

        return $result;
    }

    public function history(array $passport, array $elements, bool $vessel): array
    {
        $members = array_values(array_filter($elements, fn ($element) => $element['scope_id'] === 'vessel'));
        $dates = [];
        foreach ($members as $member) {
            foreach ($member['series'] as $entry) {
                $dates[$entry['date']] = true;
            }
        }
        $dates = array_keys($dates);
        sort($dates);
        $history = [];
        $previous = null;
        foreach ([...$dates, null] as $date) {
            $long = [];
            $short = [];
            foreach ($members as $member) {
                $entries = $date === null ? $member['series'] : array_filter($member['series'], fn ($entry) => $entry['date'] <= $date);
                $entry = $entries ? $entries[array_key_last($entries)] : null;
                if (! $entry) {
                    continue;
                }
                foreach (['nominal', 'first'] as $base) {
                    if ($entry['rates']['min'][$base] !== null) {
                        $long[] = $entry['rates']['min'][$base];
                    }
                }
                if ($entry['rates']['min']['short'] !== null) {
                    $short[] = $entry['rates']['min']['short'];
                }
            }
            $value = $this->calculate($passport['ntd'] ?? null, $passport['registration_authority'] ?? null, $long ? max($long) : null, $short ? max($short) : null, $passport['design_corrosion_mm_year'] ?? null, $vessel);
            if ($date === null) {
                return ['current' => $value, 'history' => $history];
            }
            $periods = [$value['otn_years'], $value['rtn_years'], $value['hydro_years']];
            $history[] = ['date' => $date, 'changed' => $previous !== null && $previous !== $periods] + $value;
            $previous = $periods;
        }

        return ['current' => null, 'history' => $history];
    }
}
