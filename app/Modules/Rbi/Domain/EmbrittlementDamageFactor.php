<?php

namespace App\Modules\Rbi\Domain;

use App\Modules\Rbi\Domain\Susceptibility\RuleInputs;
use InvalidArgumentException;

final class EmbrittlementDamageFactor
{
    public const VERSION = 'df-embrittlement-v1';

    public function calculate(array $input, string $kind, array $tables): array
    {
        if (! in_array($kind, ['bf', 'lase', 'e885', 'sigma'], true)) {
            throw new InvalidArgumentException('Неизвестное семейство охрупчивания.');
        }
        foreach (['mdmt', 'upset', 'tmin', 'paz', 'tsd', 'tref885'] as $key) {
            RuleInputs::number($input, $key, -273.15);
        }
        foreach (['ys', 'tbm', 'dfatt'] as $key) {
            RuleInputs::number($input, $key, 0);
        }
        $interpolation = new TabulatedInterpolation;
        $r = ['kind' => $kind, 'flows' => []];
        $missing = [];
        if ($kind !== 'sigma') {
            $candidates = array_values(array_filter([$input['mdmt'] ?? null, $input['upset'] ?? null, ...($kind === 'bf' ? [$input['tmin'] ?? null] : [])], fn ($value) => $value !== null));
            $r['cet'] = $input['paz'] ?? ($candidates ? min($candidates) : null);
            if ($r['cet'] === null) {
                $missing[] = 'critical_temperature';
            }
        }
        if (in_array($kind, ['bf', 'lase'], true)) {
            $curve = $input['curve'] ?? null;
            $yield = $input['ys'] ?? null;
            $lowAlloy = RuleInputs::flag($input, 'la');
            $r['tref'] = null;
            if ($curve !== null && ! in_array($curve, ['A', 'B', 'C', 'D'], true)) {
                throw new InvalidArgumentException('Неизвестная кривая ASME.');
            }
            if ($curve !== null && $yield !== null) {
                if ($lowAlloy === null) {
                    $missing[] = 'la';
                } else {
                    $table = $tables[$lowAlloy ? 'e33la' : 'e33cs'] ?? [];
                    $index = array_search($curve, ['A', 'B', 'C', 'D'], true) + 1;
                    $r['tref'] = $interpolation->linear(array_column($table, 0), array_column($table, $index), (float) $yield);
                }
            }
            if ($r['tref'] === null && $kind === 'lase' && ($curve === null || $yield === null)) {
                $r['tref'] = 28;
            }
            if ($r['tref'] === null && $kind === 'bf') {
                $missing[] = 'reference_temperature';
            }
            $pwht = RuleInputs::flag($input, 'pwht');
            if ($pwht === null) {
                $missing[] = 'pwht';
            }
            $thickness = $input['tbm'] ?? null;
            if ($thickness === null) {
                $missing[] = 'tbm';
            } elseif ($thickness <= 0) {
                throw new InvalidArgumentException('Толщина должна быть положительной.');
            }
            $dfTable = $tables[$pwht ? 'e35' : 'e34'] ?? [];
        }
        if ($kind === 'bf') {
            $r['d'] = $r['cet'] !== null && $r['tref'] !== null ? $r['cet'] - $r['tref'] : null;
            $r['DfB'] = $r['d'] !== null && $thickness !== null ? $interpolation->bilinear($tables['e34t'] ?? [], $tables['e34d'] ?? [], $dfTable, (float) $thickness, $r['d']) : null;
            $r['F1'] = $thickness !== null && $thickness <= 12.7 ? 0.01 : 1;
            $conditions = $input['fse'] ?? null;
            $known = is_array($conditions) && array_is_list($conditions) && count($conditions) === 7;
            if ($known) {
                foreach ($conditions as $condition) {
                    if (! is_bool($condition['on'] ?? null)) {
                        $known = false;
                        break;
                    }
                }
            }
            if (! $known) {
                $missing[] = 'fse';
            }
            $r['F2'] = $known && ! in_array(false, array_column($conditions, 'on'), true) ? 0.01 : 1;
            $r['DF'] = $r['DfB'] === null ? null : $r['DfB'] * $r['F1'] * $r['F2'];
            $r['artV'] = $this->display($r['d']);
            $r['srpV'] = $this->display($r['cet']);
            $r['dfbT'] = 'DfB = '.$this->display($r['DfB']).($pwht ? ' · PWHT' : ' · без PWHT');
        } elseif ($kind === 'lase') {
            $r['dfatt'] = $input['dfatt'] ?? null;
            if ($r['dfatt'] === null) {
                $missing[] = 'dfatt';
            }
            $r['sum'] = $r['tref'] !== null && $r['dfatt'] !== null ? $r['tref'] + $r['dfatt'] : null;
            $r['d'] = $r['cet'] !== null && $r['sum'] !== null ? max(-56, $r['cet'] - $r['sum']) : null;
            $r['DF'] = $r['d'] !== null && $thickness !== null ? $interpolation->bilinear($tables['e34t'] ?? [], $tables['e34d'] ?? [], $dfTable, (float) $thickness, $r['d']) : null;
            $r['artV'] = $this->display($r['d']);
            $r['srpV'] = $this->display($r['dfatt']);
            $r['dfbT'] = 'Tref '.$this->display($r['tref']).' °C · Tmpt '.$this->display($r['cet']).' °C';
        } elseif ($kind === 'e885') {
            $r['tref'] = $input['tref885'] ?? 28;
            $r['d'] = $r['cet'] === null ? null : $r['cet'] - $r['tref'];
            $r['DF'] = $r['d'] === null ? null : ($r['d'] > 56 ? 0 : $interpolation->linear($tables['e53d'] ?? [], $tables['e53'] ?? [], $r['d']));
            $r['artV'] = $this->display($r['d']);
            $r['srpV'] = $this->display($r['tref']);
            $r['dfbT'] = 'Tmpt '.$this->display($r['cet']).' °C';
        } else {
            $temperatures = array_values(array_filter([$input['tmin'] ?? null, $input['tsd'] ?? null, $input['upset'] ?? null], fn ($value) => $value !== null));
            $r['t'] = $temperatures ? min($temperatures) : null;
            if ($r['t'] === null) {
                $missing[] = 'minimum_temperature';
            }
            $group = null;
            foreach ($tables['e63'] ?? [] as $row) {
                if ($row['g'] === ($input['grp'] ?? null)) {
                    $group = $row;
                    break;
                }
            }
            if ($group === null) {
                $missing[] = 'grp';
            }
            $r['DF'] = $r['t'] !== null && $group !== null ? $interpolation->linear($tables['e63t'] ?? [], $group['v'], $r['t']) : null;
            $r['artV'] = $this->display($r['t']);
            $r['srpV'] = $input['grp'] ?? '—';
            $r['dfbT'] = '';
        }
        if ($r['DF'] === null && ! $missing) {
            $missing[] = 'embrittlement_table';
        }
        if ($missing) {
            $r['DF'] = null;
        }
        if ($r['DF'] !== null && (! is_finite((float) $r['DF']) || $r['DF'] < 0)) {
            throw new InvalidArgumentException('Некорректный результат охрупчивания.');
        }
        $r['agecrack'] = null;
        $steps = [];
        foreach (['cet' => 'Критическая температура', 'tref' => 'Эталонная температура', 'dfatt' => 'Сдвиг ΔFATT', 'd' => 'Разность температур', 't' => 'Минимальная температура', 'DfB' => 'Базовый фактор повреждения', 'F1' => 'Поправка на толщину', 'F2' => 'Подтверждённые условия снижения FSE', 'DF' => 'Фактор повреждения'] as $key => $label) {
            if (array_key_exists($key, $r)) {
                $steps[] = ['key' => $key, 'label' => $label, 'value' => $r[$key], 'unit' => in_array($key, ['cet', 'tref', 'dfatt', 'd', 't'], true) ? '°C' : '—'];
            }
        }

        return ['method' => self::VERSION, 'kind' => $kind, 'ready' => $missing === [], 'missing' => array_values(array_unique($missing)), 'res' => $r, 'steps' => $steps];
    }

    private function display(?float $value): string
    {
        return $value === null ? '—' : str_replace('.', ',', (string) (floor($value * 10 + 0.5) / 10));
    }
}
