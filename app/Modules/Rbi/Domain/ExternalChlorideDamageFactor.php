<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

final class ExternalChlorideDamageFactor
{
    public const VERSION = 'df-external-chloride-v1';

    private const LEVELS = ['нет', 'низкая', 'средняя', 'высокая'];

    public function calculate(array $input, string $kind = 'ecl'): array
    {
        if (! in_array($kind, ['ecl', 'ccl'], true)) {
            throw new InvalidArgumentException('Неизвестное семейство хлоридного растрескивания.');
        }
        $cui = $kind === 'ccl';
        foreach (['tAn', 'tIn', 'T', 'tCrk', 'tCoat', 'Cage'] as $key) {
            if (isset($input[$key]) && (! is_numeric($input[$key]) || ! is_finite((float) $input[$key]))) {
                throw new InvalidArgumentException('Некорректный числовой вход SCC: '.$key);
            }
        }
        foreach (['coat', 'coatFailed', 'crkLast', 'clFree'] as $key) {
            if (isset($input[$key]) && ! is_bool($input[$key])) {
                throw new InvalidArgumentException('Логический признак SCC должен быть явно указан.');
            }
        }
        $required = ['tAn', 'tIn', 'crkLast', 'coat', 'svT', 't13'];
        if (! ($input['crkLast'] ?? false)) {
            $required = [...$required, 'T', 'drv', 'susT'];
        }
        if ($input['coat'] ?? false) {
            $required = [...$required, 'tCoat', 'Cage'];
        }
        $missing = array_values(array_filter($required, fn ($key) => ! isset($input[$key])));
        if ($missing) {
            return $this->blocked($kind, $missing);
        }
        $base = null;
        if (isset($input['T'],$input['drv'],$input['susT'])) {
            foreach ($input['susT'] as $row) {
                if (($row['lo'] === null || $input['T'] >= $row['lo']) && ($row['hi'] === null || $input['T'] < $row['hi'])) {
                    $base = $row[$input['drv']] ?? null;
                    break;
                }
            }
        }
        $r = ['base' => $base];
        if ($cui) {
            $missing = [];
            foreach (['fcm', 'ins', 'clFree'] as $key) {
                if (! isset($input[$key]) && ! $input['crkLast'] && $base !== 'нет') {
                    $missing[] = $key;
                }
            }
            if ($missing) {
                return $this->blocked($kind, $missing);
            }
            if (isset($input['fcm']) && ! in_array($input['fcm'], ['низкая', 'средняя', 'высокая'], true)) {
                throw new InvalidArgumentException('Неизвестная сложность конструкции.');
            }
            if (isset($input['ins']) && ! in_array($input['ins'], ['above average', 'average', 'below average', 'unknown'], true)) {
                throw new InvalidArgumentException('Неизвестное состояние изоляции.');
            }
            $r['aC'] = isset($input['fcm']) ? match ($input['fcm']) {
                'высокая' => 1,'низкая' => -1,default => 0
            } : null;
            $r['aI'] = isset($input['ins']) ? match ($input['ins']) {
                'above average' => -1,'average' => 0,default => 1
            } : null;
            $r['aCl'] = isset($input['clFree']) ? ($input['clFree'] ? -1 : 0) : null;
            $r['sum'] = in_array(null, [$r['aC'], $r['aI'], $r['aCl']], true) ? null : $r['aC'] + $r['aI'] + $r['aCl'];
            $level = array_search($base, self::LEVELS, true);
            $r['adj'] = $level === false ? null : ($level === 0 ? 'нет' : ($r['sum'] === null ? null : self::LEVELS[max(0, min(3, $level + $r['sum']))]));
        } else {
            $r['adj'] = $base;
        }
        $r['susc'] = $input['crkLast'] ? 'высокая' : $r['adj'];
        if ($r['susc'] === null) {
            return $this->blocked($kind, ['susceptibility_table']);
        }
        $r['SVI'] = null;
        foreach ($input['svT'] as $row) {
            if ($row['s'] === $r['susc']) {
                $r['SVI'] = $row[$cui ? 'cui' : 'ext'] ?? null;
                break;
            }
        }
        if ($r['SVI'] === null) {
            return $this->blocked($kind, ['severity_table']);
        }
        if (! is_numeric($r['SVI']) || ! is_finite((float) $r['SVI']) || $r['SVI'] < 0) {
            throw new InvalidArgumentException('Некорректный показатель SVI.');
        }
        $r['age'] = $input['tAn'] - $input['tIn'];
        $r['agecrack'] = isset($input['tCrk']) ? $input['tAn'] - $input['tCrk'] : $r['age'];
        if ($r['age'] < 0 || $r['agecrack'] < 0) {
            throw new InvalidArgumentException('Даты SCC не согласованы.');
        }
        $r['agecoat'] = $input['coat'] ? $input['tAn'] - $input['tCoat'] : null;
        if ($input['coat'] && ($r['agecoat'] < 0 || $input['Cage'] < 0)) {
            throw new InvalidArgumentException('Дата или срок службы покрытия некорректны.');
        }
        $cage = $input['coat'] ? $input['Cage'] : 0;
        if (! $input['coat'] || $cage <= 0) {
            $credit = 0;
        } elseif ($r['agecrack'] >= $r['agecoat']) {
            $credit = min($cage, $r['agecoat']);
        } else {
            if (! isset($input['coatFailed'])) {
                return $this->blocked($kind, ['coatFailed']);
            } $credit = $input['coatFailed'] ? 0 : min($cage, $r['agecoat']) - min($cage, $r['agecoat'] - $r['agecrack']);
        }
        $r['Coatadj'] = max($credit, 0);
        $r['ageX'] = max($r['agecrack'] - $r['Coatadj'], 0);
        $r += (new SccInspectionModel)->describe($input['N'] ?? [], (float) $r['SVI'], $input['t13']);
        if ($r['DfB'] === null) {
            return $this->blocked($kind, ['scc_damage_table_row']);
        }
        $r['DF'] = min($r['DfB'] * pow(max($r['ageX'], 1), 1.1), 5000);
        if (! is_finite($r['DF'])) {
            throw new InvalidArgumentException('Переполнение DF хлоридного растрескивания.');
        }
        $steps = [];
        foreach ([['base', 'Базовая восприимчивость', '—'], ['susc', 'Принятая восприимчивость', '—'], ['SVI', 'Показатель SVI', '—'], ['agecrack', 'Время с инспекции без трещин', 'лет'], ['Coatadj', 'Корректировка на покрытие', 'лет'], ['ageX', 'Период развития растрескивания', 'лет'], ['Nc', 'Эквивалентные инспекции', 'шт.'], ['DfB', 'Базовый фактор повреждения', '—'], ['DF', 'Фактор повреждения', '—']] as [$key,$label,$unit]) {
            $steps[] = ['key' => $key, 'label' => $label, 'value' => $r[$key], 'unit' => $unit];
        }

        return ['method' => self::VERSION, 'kind' => $kind, 'ready' => true, 'missing' => [], 'res' => $r, 'steps' => $steps];
    }

    private function blocked(string $kind, array $missing): array
    {
        return ['method' => self::VERSION, 'kind' => $kind, 'ready' => false, 'missing' => $missing, 'res' => ['DF' => null], 'steps' => []];
    }
}
