<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

/** API 581 thinning equations, versioned against the supplied legacy v7.10 kernel. */
final class ThinningDamageFactor
{
    public const VERSION = 'df-thin-v1';

    public function calculate(array $input): array
    {
        foreach ($input as $key => $value) {
            if (in_array($key, ['N', 'prp', 'cop'], true) || $value === null) {
                continue;
            }
            if ((! is_int($value) && ! is_float($value)) || ! is_finite($value)) {
                throw new InvalidArgumentException('Некорректный числовой вход DF утонения: '.$key);
            }
        }
        $missing = [];
        $measuredKeys = ['CrM', 'trdiM', 'tLast'];
        $measuredCount = count(array_filter($measuredKeys, fn ($key) => isset($input[$key])));
        $measured = $measuredCount === 3;
        $required = ['tAn', 'tIn', 'YS', 'TS', 'E', 'FOM', 'FIP', 'FDL'];
        if ($measuredCount > 0) {
            $required = [...$required, ...$measuredKeys];
        } else {
            $required = [...$required, 'CrT', 'tbm'];
        }
        $equation14 = false;
        if (isset($input['tc'])) {
            $required[] = 'tmin';
            if (isset($input['tmin'])) {
                $equation14 = abs($input['tc'] - $input['tmin']) < 1e-9 || $input['tc'] > $input['tmin'];
            }
        }
        $required = [...$required, ...($equation14 ? ['S'] : ['P', 'D', 'alpha'])];
        foreach (array_unique($required) as $key) {
            if (! isset($input[$key])) {
                $missing[] = $key;
            }
        }
        foreach (['prp', 'cop'] as $key) {
            if (! isset($input[$key])) {
                $missing[] = $key;
            }
        }
        if ($missing) {
            return ['method' => self::VERSION, 'ready' => false, 'missing' => array_values(array_unique($missing)), 'res' => ['DF' => null], 'steps' => []];
        }
        foreach (['YS', 'TS', 'E', 'FOM', 'FIP', 'FDL', ...($measured ? ['trdiM'] : ['tbm']), ...($equation14 ? ['S', 'tmin'] : ['D', 'alpha'])] as $key) {
            if ($input[$key] <= 0) {
                throw new InvalidArgumentException('Положительное значение обязательно: '.$key);
            }
        }
        if ($input['E'] > 1 || $input['TS'] < $input['YS']) {
            throw new InvalidArgumentException('Прочностные характеристики материала противоречивы.');
        }
        if ($input['tAn'] < $input['tIn'] || $measured && ($input['tLast'] > $input['tAn'] || $input['tLast'] < $input['tIn'])) {
            throw new InvalidArgumentException('Даты расчёта, ввода и замера не согласованы.');
        }
        if (! $measured && $input['CrT'] < 0 || isset($input['P']) && $input['P'] < 0 || isset($input['tc']) && $input['tc'] < 0) {
            throw new InvalidArgumentException('Отрицательный проектный параметр DF утонения.');
        }
        $r = ['age10' => $input['tAn'] - $input['tIn'], 'measured' => $measured];
        $r['Cr'] = $measured ? $input['CrM'] : $input['CrT'];
        $r['CrSrc'] = $measured ? 'измеренная (наибольшая)' : 'проектная (замеров нет)';
        $r['agetk'] = $measured ? $input['tAn'] - $input['tLast'] : $r['age10'];
        $r['trdi'] = $measured ? $input['trdiM'] : $input['tbm'];
        $r['Art'] = max($r['Cr'] * $r['agetk'] / $r['trdi'], 0);
        $r['FS'] = ($input['YS'] + $input['TS']) / 2 * $input['E'] * 1.1;
        if (! is_finite($r['FS']) || $r['FS'] <= 0) {
            throw new InvalidArgumentException('Переполнение напряжения пластической деформации.');
        }
        $r['SRP14'] = isset($input['S'],$input['tmin']) ? ($input['S'] * $input['E'] / $r['FS']) * (max($input['tmin'], $input['tc'] ?? -INF) / $r['trdi']) : null;
        $r['SRP15'] = isset($input['P'],$input['D'],$input['alpha']) ? $input['P'] * $input['D'] / ($input['alpha'] * $r['FS'] * $r['trdi']) : null;
        $r['srpEq'] = $equation14 ? '2.14' : '2.15';
        $r['srpWhy'] = ! isset($input['tc']) ? 'tc не задана → ур. 2.15' : (abs($input['tc'] - $input['tmin']) < 1e-9 ? 'tc = tmin → ур. 2.14' : ($input['tc'] < $input['tmin'] ? 'tc < tmin → ур. 2.15' : 'tc > tmin → ур. 2.14 с max(tmin, tc)'));
        $r['SRP'] = $equation14 ? $r['SRP14'] : $r['SRP15'];
        $r += (new InspectionReliability)->calculate($r['Art'], $r['SRP'], $input);
        $r['DF'] = max($r['Dfb'] * $input['FIP'] * $input['FDL'] / $input['FOM'], 0.1);
        foreach ($r as $value) {
            foreach (is_array($value) ? $value : [$value] as $number) {
                if (is_float($number) && ! is_finite($number)) {
                    throw new InvalidArgumentException('Переполнение при расчёте DF утонения.');
                }
            }
        }
        $steps = [];
        foreach ([['age10', 'Длительность эксплуатации', 'лет'], ['Cr', 'Скорость коррозии', 'мм/год'], ['trdi', 'Исходная толщина', 'мм'], ['Art', 'Параметр Art', '—'], ['FS', 'Напряжение пластической деформации', 'МПа'], ['SRP', 'Коэффициент прочности SRP', '—'], ['Cop', 'Условные вероятности', '—'], ['Pop', 'Апостериорные вероятности', '—'], ['beta', 'Показатели надёжности', '—'], ['Dfb', 'Базовый фактор повреждения', '—'], ['DF', 'Фактор повреждения', '—']] as [$key,$label,$unit]) {
            $steps[] = ['key' => $key, 'label' => $label, 'value' => $r[$key], 'unit' => $unit];
        }

        return ['method' => self::VERSION, 'ready' => true, 'missing' => [], 'res' => $r, 'steps' => $steps];
    }
}
