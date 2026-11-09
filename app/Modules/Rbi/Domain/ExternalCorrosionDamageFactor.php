<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

final class ExternalCorrosionDamageFactor
{
    public const VERSION = 'df-external-v1';

    public function calculate(array $input, string $kind = 'ext'): array
    {
        if (! in_array($kind, ['ext', 'cui'], true)) {
            throw new InvalidArgumentException('Неизвестное семейство внешней коррозии.');
        }
        $cui = $kind === 'cui';
        foreach ($input as $key => $value) {
            if (in_array($key, ['N', 'prp', 'cop', 'coat', 'coatFailed', 'CageWhy'], true) || $value === null) {
                continue;
            }
            if ((! is_float($value) && ! is_int($value)) || ! is_finite($value)) {
                throw new InvalidArgumentException('Некорректный числовой вход внешней коррозии: '.$key);
            }
        }
        foreach (['coat', 'coatFailed'] as $key) {
            if (isset($input[$key]) && ! is_bool($input[$key])) {
                throw new InvalidArgumentException('Признак покрытия должен быть логическим значением.');
            }
        }
        $equationA = isset($input['tc'],$input['tmin']) && (abs($input['tc'] - $input['tmin']) < 1e-9 || $input['tc'] > $input['tmin']);
        $required = ['tAn', 'tIn', 'tbm', 'CrB', 'FEQ', 'FIF', 'YS', 'TS', 'E', 'prp', 'cop', 'coat', ...($equationA ? ['S', 'tmin'] : ['P', 'D', 'alpha'])];
        if ($cui) {
            $required = [...$required, 'FINS', 'FCM', 'FIC'];
        }
        if (isset($input['tc'])) {
            $required[] = 'tmin';
        }
        if (isset($input['Le']) || isset($input['tLe'])) {
            $required = [...$required, 'Le', 'tLe'];
        }
        if ($input['coat'] ?? false) {
            $required = [...$required, 'tCoat', 'Cage'];
        }
        $missing = array_values(array_filter(array_unique($required), fn ($key) => ! isset($input[$key])));
        if ($missing) {
            return $this->blocked($kind, $missing);
        }
        $measured = isset($input['tLe'],$input['Le']);
        $r = ['age' => $input['tAn'] - $input['tIn'], 'Fmax' => max($input['FEQ'], $input['FIF'])];
        if ($r['age'] < 0 || $measured && ($input['tLe'] > $input['tAn'] || $input['tLe'] < $input['tIn'])) {
            throw new InvalidArgumentException('Даты внешней коррозии не согласованы.');
        }
        foreach (['tbm', 'FEQ', 'FIF', 'YS', 'TS', 'E', ...($cui ? ['FINS', 'FCM', 'FIC'] : []), ...($equationA ? ['S', 'tmin'] : ['D', 'alpha'])] as $key) {
            if ($input[$key] <= 0) {
                throw new InvalidArgumentException('Положительное значение обязательно: '.$key);
            }
        }
        if ($input['E'] > 1 || $input['TS'] < $input['YS'] || $input['CrB'] < 0 || isset($input['P']) && $input['P'] < 0 || isset($input['tc']) && $input['tc'] < 0) {
            throw new InvalidArgumentException('Исходные параметры внешней коррозии противоречивы.');
        }
        $r['Cr'] = $input['CrB'] * $r['Fmax'] * ($cui ? $input['FINS'] * $input['FCM'] * $input['FIC'] : 1);
        $r['measured'] = $measured;
        $r['agetke'] = $measured ? $input['tAn'] - $input['tLe'] : $r['age'];
        if ($measured && $input['Le'] < 0) {
            throw new InvalidArgumentException('Измеренная потеря толщины не может быть отрицательной.');
        }
        $r['trde'] = $measured ? $input['tbm'] - $input['Le'] : $input['tbm'];
        if ($r['trde'] <= 0) {
            throw new InvalidArgumentException('После потери толщины металл должен иметь положительную толщину.');
        }
        $r['agecoat'] = $input['coat'] ? $input['tAn'] - $input['tCoat'] : null;
        if ($input['coat'] && ($r['agecoat'] < 0 || $input['Cage'] < 0)) {
            throw new InvalidArgumentException('Дата или срок службы покрытия некорректны.');
        }
        if (! $input['coat']) {
            $credit = 0;
        } elseif ($r['agetke'] >= $r['agecoat']) {
            $credit = min($input['Cage'], $r['agecoat']);
        } else {
            if (! isset($input['coatFailed'])) {
                return $this->blocked($kind, ['coatFailed']);
            }
            $credit = $input['coatFailed'] ? 0 : min($input['Cage'], $r['agecoat']) - min($input['Cage'], $r['agecoat'] - $r['agetke']);
        }
        $r['Coatadj'] = max($credit, 0);
        $r['ageX'] = max($r['agetke'] - $r['Coatadj'], 0);
        $r['Art'] = max($r['Cr'] * $r['ageX'] / $r['trde'], 0);
        $r['FS'] = ($input['YS'] + $input['TS']) / 2 * $input['E'] * 1.1;
        if (! is_finite($r['FS']) || $r['FS'] <= 0) {
            throw new InvalidArgumentException('Переполнение прочностных характеристик.');
        }
        $r['SRP8'] = isset($input['S'],$input['tmin']) ? ($input['S'] * $input['E'] / $r['FS']) * (max($input['tmin'], $input['tc'] ?? -INF) / $r['trde']) : null;
        $r['SRP9'] = isset($input['P'],$input['D'],$input['alpha']) ? $input['P'] * $input['D'] / ($input['alpha'] * $r['FS'] * $r['trde']) : null;
        $a = $cui ? '2.D.22' : '2.D.8';
        $b = $cui ? '2.D.23' : '2.D.9';
        $r['srpEq'] = $equationA ? $a : $b;
        $r['srpWhy'] = ! isset($input['tc']) ? 'tc не задана → ур. '.$b : (abs($input['tc'] - $input['tmin']) < 1e-9 ? 'tc = tmin → ур. '.$a : ($input['tc'] < $input['tmin'] ? 'tc < tmin → ур. '.$b : 'tc > tmin → ур. '.$a.' с max(tmin, tc)'));
        $r['SRP'] = $equationA ? $r['SRP8'] : $r['SRP9'];
        $r += (new InspectionReliability)->calculate($r['Art'], $r['SRP'], $input);
        $r['DF'] = $r['Dfb'];
        unset($r['Dfb']);
        foreach ($r as $value) {
            foreach (is_array($value) ? $value : [$value] as $number) {
                if (is_float($number) && ! is_finite($number)) {
                    throw new InvalidArgumentException('Переполнение DF внешней коррозии.');
                }
            }
        }
        $steps = [];
        foreach ([['Cr', 'Скорость внешней коррозии', 'мм/год'], ['trde', 'Толщина после измеренной потери', 'мм'], ['agecoat', 'Возраст покрытия', 'лет'], ['Coatadj', 'Корректировка на покрытие', 'лет'], ['ageX', 'Период развития коррозии', 'лет'], ['Art', 'Параметр Art', '—'], ['FS', 'Напряжение пластической деформации', 'МПа'], ['SRP', 'Коэффициент прочности SRP', '—'], ['Pop', 'Апостериорные вероятности', '—'], ['beta', 'Показатели надёжности', '—'], ['DF', 'Фактор повреждения', '—']] as [$key,$label,$unit]) {
            $steps[] = ['key' => $key, 'label' => $label, 'value' => $r[$key], 'unit' => $unit];
        }

        return ['method' => self::VERSION, 'kind' => $kind, 'ready' => true, 'missing' => [], 'res' => $r, 'steps' => $steps];
    }

    private function blocked(string $kind, array $missing): array
    {
        return ['method' => self::VERSION, 'kind' => $kind, 'ready' => false, 'missing' => $missing, 'res' => ['DF' => null], 'steps' => []];
    }
}
