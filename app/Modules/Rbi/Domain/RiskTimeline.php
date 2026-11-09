<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

/** Pure prospective scenarios: calculations never write inspection history or accepted plans. */
final class RiskTimeline
{
    public const VERSION = 'risk-timeline-v1';

    private const SCC = ['ssc', 'hich2s', 'hichf', 'hschf', 'acscc', 'aminescc', 'causticscc', 'clscc', 'pascc'];

    private const GROUPS = ['thin' => ['thin'], 'ext' => ['ext', 'cui', 'ecl', 'ccl'], 'scc' => self::SCC];

    public function calculate(array $input, array $targets, array $tables, ?DamageFactorEngine $engine = null): array
    {
        $engine ??= new DamageFactorDispatcher;
        $missing = [];
        $errors = [];
        foreach (['tAn', 'gff', 'Fms', 'ti', 'te', 'kinds'] as $key) {
            if (! isset($input[$key])) {
                $missing[] = $key;
            }
        }
        foreach (['area', 'fin', 'pof', 'dfmax', 'dThin', 'dScc', 'dExt', 'intvl', 'planRec', 'planMax', 'planScc', 'H'] as $key) {
            if (! array_key_exists($key, $targets)) {
                $missing[] = 'targets.'.$key;
            }
        }
        if ($missing) {
            return $this->blocked($missing);
        }
        foreach (['tAn', 'gff', 'Fms', 'CA', 'FC', 'tLastInsp'] as $key) {
            if (isset($input[$key]) && (! is_numeric($input[$key]) || ! is_finite((float) $input[$key]) || $input[$key] < 0)) {
                throw new InvalidArgumentException('Некорректный вход риска: '.$key);
            }
        }
        foreach ($targets as $key => $value) {
            if ($value !== null && (! is_numeric($value) || ! is_finite((float) $value) || $value < 0)) {
                throw new InvalidArgumentException('Некорректная цель риска: '.$key);
            }
        }
        if (! is_array($input['kinds']) || array_diff(array_keys($input['kinds']), DamageFactorAggregator::MECHANISMS)) {
            throw new InvalidArgumentException('Неизвестный набор механизмов риска.');
        }
        if (! in_array($input['ti'], ['общая', 'локальная'], true) || ! in_array($input['te'], ['общая', 'локальная'], true)) {
            throw new InvalidArgumentException('Укажите тип внутренней и наружной коррозии.');
        }
        if (! isset($targets['H'],$targets['planRec'],$targets['planScc']) || $targets['H'] < 0.5 || $targets['H'] > 100 || abs($targets['H'] * 2 - round($targets['H'] * 2)) > 1e-9) {
            throw new InvalidArgumentException('Горизонт риска должен быть кратен 0,5 года и не превышать 100 лет.');
        }
        foreach (['dThin', 'dExt', 'dScc'] as $key) {
            if (! isset($targets[$key])) {
                throw new InvalidArgumentException('Минимальный целевой DF группы должен быть задан.');
            }
        }
        $step = 0.5;
        $count = (int) floor($targets['H'] / $step + 0.5);
        $times = [];
        for ($index = 0; $index <= $count; $index++) {
            $times[] = $input['tAn'] + $index * $step;
        }
        $mechanisms = array_keys($input['kinds']);
        $aggregator = new DamageFactorAggregator;
        $at = function (array $kinds, float $time) use ($engine, $tables, $input, $aggregator, &$errors): ?array {
            $factors = [];
            foreach ($kinds as $kind => $parameters) {
                try {
                    $result = $engine->calculate($kind, array_replace($parameters, ['tAn' => $time]), $tables);
                } catch (InvalidArgumentException $exception) {
                    $errors[] = ['mechanism' => $kind, 'time' => $time, 'message' => $exception->getMessage()];

                    return null;
                }
                if (! $result['ready'] || ! isset($result['res']['DF'])) {
                    $errors[] = ['mechanism' => $kind, 'time' => $time, 'missing' => $result['missing'] ?? ['DF']];

                    return null;
                }
                $factors[$kind] = $result['res']['DF'];
            }
            $combined = $aggregator->calculate($factors, array_keys($kinds), $input['ti'], $input['te']);
            if (! $combined['ready']) {
                return null;
            }
            $groups = $combined['groups'];
            $groups = ['thin' => $groups['thin'], 'scc' => $groups['scc'], 'ext' => $groups['extd'], 'brit' => $groups['brit'], 'htha' => $groups['htha'], 'dft' => $combined['total']];
            $pof = $input['gff'] * $input['Fms'] * $groups['dft'];
            $area = isset($input['CA']) ? $pof * $input['CA'] : null;
            $financial = isset($input['FC']) ? $pof * $input['FC'] : null;
            foreach ([$pof, $area, $financial] as $value) {
                if ($value !== null && ! is_finite($value)) {
                    throw new InvalidArgumentException('Переполнение прогноза риска.');
                }
            }

            return ['d' => $factors, 'g' => $groups, 'pof' => $pof, 'ra' => $area, 'rf' => $financial];
        };
        $without = [];
        foreach ($times as $time) {
            $point = $at($input['kinds'], $time);
            if ($point === null) {
                return $this->blocked(['damage_factor_series'], $errors);
            }$without[] = $point;
        }
        $thin = $input['thin'] ?? null;
        $thickness = [];
        if ($thin !== null) {
            foreach (['trdi', 'Cr', 'tLast', 'tmin'] as $key) {
                if (isset($thin[$key]) && (! is_numeric($thin[$key]) || ! is_finite((float) $thin[$key]))) {
                    throw new InvalidArgumentException('Некорректный вход пороговой толщины.');
                }
            }
        }
        foreach ($times as $time) {
            $thickness[] = isset($thin['trdi'],$thin['Cr'],$thin['tLast']) ? $thin['trdi'] - $thin['Cr'] * max(0, $time - $thin['tLast']) : null;
        }
        $dates = ['area' => $this->cross($times, array_column($without, 'ra'), $targets['area']), 'fin' => $this->cross($times, array_column($without, 'rf'), $targets['fin']),
            'pof' => $this->cross($times, array_column($without, 'pof'), $targets['pof']), 'df' => $this->cross($times, array_map(fn ($point) => $point['g']['dft'], $without), $targets['dfmax']),
            'thk' => isset($thin['tmin']) ? $this->cross($times, $thickness, $thin['tmin'], true) : null,
            'int' => isset($input['tLastInsp'],$targets['intvl']) ? max($input['tAn'], $input['tLastInsp'] + $targets['intvl']) : null];
        if ($dates['int'] !== null && $dates['int'] > $times[array_key_last($times)]) {
            $dates['int'] = null;
        }
        $final = null;
        $governing = null;
        foreach ($dates as $key => $value) {
            if ($value !== null && ($final === null || $value < $final)) {
                $final = $value;
                $governing = $key;
            }
        }
        $planRecommended = $input['tAn'] + $targets['planRec'];
        $planScc = $input['tAn'] + $targets['planScc'];
        $inspectionTime = $final ?? $planRecommended;
        $before = $at($input['kinds'], $inspectionTime);
        if ($before === null) {
            return $this->blocked(['before_inspection'], $errors);
        }
        $minimum = ['thin' => $targets['dThin'], 'ext' => $targets['dExt'], 'scc' => $targets['dScc']];
        $plan = ['thin' => $planRecommended, 'ext' => $planRecommended, 'scc' => $planScc];
        $categories = [];
        $inspectKinds = [];
        foreach (self::GROUPS as $group => $members) {
            $keys = array_values(array_intersect($members, $mechanisms));
            $series = array_map(fn ($point) => $point['g'][$group], $without);
            $active = $keys !== [] && count(array_filter($series, fn ($value) => $value > 0)) > 0;
            if (! $active) {
                $categories[$group] = ['need' => false, 'act' => false, 'X' => null, 'why' => 'МД не активен'];

                continue;
            }
            $minimumDate = $this->cross($times, $series, $minimum[$group]);
            $effective = $minimumDate !== null && $minimumDate <= $plan[$group];
            if ($before['g'][$group] < $minimum[$group]) {
                $categories[$group] = ['need' => false, 'act' => true, 'X' => null, 'dMin' => $minimumDate, 'eff' => $effective, 'dfB' => $before['g'][$group], 'dfA' => $before['g'][$group], 'why' => 'Df-gov ниже минимального целевого на дату инспекции'];

                continue;
            }
            $chosen = null;
            $afterFactor = null;
            foreach (['C', 'B', 'A'] as $category) {
                $kinds = $input['kinds'];
                foreach ($keys as $key) {
                    $kinds[$key] = $this->inspect($key, $kinds[$key], $inspectionTime, $category);
                }$sample = $at($kinds, $inspectionTime);
                if ($sample === null) {
                    return $this->blocked(['inspection_scenario'], $errors);
                }$afterFactor = $sample['g'][$group];
                if ($afterFactor < $minimum[$group]) {
                    $chosen = $category;
                    break;
                }
            }
            $ok = $chosen !== null;
            $chosen ??= 'A';
            foreach ($keys as $key) {
                $inspectKinds[$key] = $chosen;
            }
            $categories[$group] = ['need' => true, 'act' => true, 'X' => $chosen, 'ok' => $ok, 'dMin' => $minimumDate, 'eff' => $effective, 'dfB' => $before['g'][$group], 'dfA' => $afterFactor,
                'why' => $ok ? 'после инспекции '.$chosen.' Df-gov < '.$minimum[$group] : 'даже после A Df-gov ≥ '.$minimum[$group].' — инспекция недостаточна'];
        }
        $inspected = $input['kinds'];
        foreach ($inspectKinds as $key => $category) {
            $inspected[$key] = $this->inspect($key, $inspected[$key], $inspectionTime, $category);
        }
        $with = [];
        foreach ($times as $index => $time) {
            if ($time < $inspectionTime - 1e-9) {
                $with[] = $without[$index];
            } else {
                $point = $at($inspected, $time);
                if ($point === null) {
                    return $this->blocked(['after_inspection_series'], $errors);
                }$with[] = $point;
            }
        }
        $after = $at($inspected, $inspectionTime);
        if ($after === null) {
            return $this->blocked(['after_inspection'], $errors);
        }
        $insufficient = count(array_filter($categories, fn ($group) => $group['need'] && ! $group['ok'])) > 0;
        $thicknessNow = isset($thin['tmin'],$thickness[0]) && $thickness[0] <= $thin['tmin'];
        $thicknessBad = $thicknessNow || $governing === 'thk';
        $extra = $thicknessBad || $insufficient;
        foreach (['ra' => 'area', 'rf' => 'fin', 'pof' => 'pof'] as $metric => $target) {
            if ($after[$metric] !== null && $targets[$target] !== null && $after[$metric] > $targets[$target]) {
                $extra = true;
            }
        }
        if ($targets['dfmax'] !== null && $after['g']['dft'] > $targets['dfmax']) {
            $extra = true;
        }
        $sum = array_sum($before['d']);
        $contributions = [];
        foreach ($before['d'] as $key => $value) {
            if ($value > 0) {
                $contributions[] = ['k' => $key, 'v' => $value, 'share' => $sum > 0 ? $value / $sum : 0];
            }
        }
        usort($contributions, fn ($a, $b) => $b['v'] <=> $a['v']);
        $projection = fn ($points) => ['dft' => array_map(fn ($point) => $point['g']['dft'], $points), 'pof' => array_column($points, 'pof'), 'ra' => array_column($points, 'ra'), 'rf' => array_column($points, 'rf')];
        $r = ['T' => $targets, 'ts' => $times, 'noI' => $projection($without), 'wI' => $projection($with), 'thk' => $thickness, 'dates' => $dates, 'final' => $final, 'gov' => $governing,
            'thkNow' => $thicknessNow, 'thkBad' => $thicknessBad, 'thk0' => $thickness[0], 'tmin' => $thin['tmin'] ?? null, 'tI' => $inspectionTime, 'byPlan' => $final === null, 'tPlanRec' => $planRecommended, 'tPlanScc' => $planScc,
            'cats' => $categories, 'before' => $before, 'after' => $after, 'insuff' => $insufficient, 'extra' => $extra, 'contrib' => $contributions];
        if ($targets['area'] !== null && ! isset($input['CA'])) {
            $missing[] = 'CA';
        }if ($targets['fin'] !== null && ! isset($input['FC'])) {
            $missing[] = 'FC';
        }
        if ($targets['intvl'] !== null && ! isset($input['tLastInsp'])) {
            $missing[] = 'tLastInsp';
        }

        return ['method' => self::VERSION, 'ready' => $missing === [], 'missing' => $missing, 'res' => $r, 'scenario_assumptions' => ['Будущая инспекция — условный сценарий; фактическая история и принятые планы не изменяются.']];
    }

    private function cross(array $times, array $series, ?float $target, bool $down = false): ?float
    {
        if ($target === null) {
            return null;
        }
        foreach ($series as $index => $value) {
            if ($value === null || ! ($down ? $value < $target : $value > $target)) {
                continue;
            }if ($index === 0) {
                return $times[0];
            }$previous = $series[$index - 1];
            if ($previous === null || $previous === $value) {
                return $times[$index];
            }$fraction = ($target - $previous) / ($value - $previous);

            return $times[$index - 1] + max(0, min(1, $fraction)) * ($times[$index] - $times[$index - 1]);
        }

        return null;
    }

    private function inspect(string $kind, array $parameters, float $time, string $category): array
    {
        if (in_array($kind, ['bf', 'lase', 'e885', 'sigma', 'htha'], true)) {
            return $parameters;
        }
        $parameters['N'] ??= [];
        $parameters['N'][$category] = ($parameters['N'][$category] ?? 0) + 1;
        if ($kind === 'thin') {
            $last = $parameters['tLast'] ?? $parameters['tIn'] ?? null;
            if (isset($parameters['trdiM'],$parameters['CrM']) && $last !== null) {
                $parameters['trdiM'] -= $parameters['CrM'] * max(0, $time - $last);
            }$parameters['tLast'] = $time;
        } elseif (in_array($kind, ['ext', 'cui'], true)) {
            $parameters['tLe'] = $time;
        } else {
            $parameters['tCrk'] = $time;
            $parameters['crkLast'] = false;
        }

        return $parameters;
    }

    private function blocked(array $missing, array $errors = []): array
    {
        return ['method' => self::VERSION, 'ready' => false, 'missing' => $missing, 'res' => null, 'errors' => $errors];
    }
}
