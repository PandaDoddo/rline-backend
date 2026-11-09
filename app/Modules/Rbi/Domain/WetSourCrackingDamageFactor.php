<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

/** SSC, HIC/SOHIC-H2S, HIC/SOHIC-HF and HSC-HF, with explicit operating fractions. */
final class WetSourCrackingDamageFactor
{
    public const VERSION = 'df-wet-sour-v1';

    private const LEVELS = ['отсутствует', 'низкая', 'средняя', 'высокая'];

    public function calculate(array $input, string $kind, array $tables): array
    {
        if (! in_array($kind, ['ssc', 'hich2s', 'hichf', 'hschf'], true)) {
            throw new InvalidArgumentException('Неизвестное семейство влажного растрескивания.');
        }
        $environment = in_array($kind, ['ssc', 'hich2s'], true);
        $monitoring = in_array($kind, ['hich2s', 'hichf'], true);
        $missing = [];
        foreach (['tAn', 'tIn', 'crkLast', 'flows'] as $key) {
            if (! isset($input[$key])) {
                $missing[] = $key;
            }
        }
        if ($monitoring) {
            foreach (['kp', 'hp'] as $key) {
                if (! isset($input[$key])) {
                    $missing[] = $key;
                }
            }
        }
        if ($missing) {
            return $this->blocked($kind, $missing);
        }
        foreach (['tAn', 'tIn', 'tCrk', 'hb'] as $key) {
            if (isset($input[$key]) && (! is_numeric($input[$key]) || ! is_finite((float) $input[$key]))) {
                throw new InvalidArgumentException('Некорректное числовое значение SCC: '.$key);
            }
        }
        foreach (['crkLast', 'pwht', 'seam', 'sHigh', 'kp', 'hp'] as $key) {
            if (isset($input[$key]) && ! is_bool($input[$key])) {
                throw new InvalidArgumentException('Признак SCC должен быть логическим значением: '.$key);
            }
        }
        if (! is_array($input['flows']) || ! $input['flows']) {
            return $this->blocked($kind, ['flows']);
        }
        $r = ['kind' => $kind, 'flows' => [], 'age' => $input['tAn'] - $input['tIn']];
        $r['agecrack'] = isset($input['tCrk']) ? $input['tAn'] - $input['tCrk'] : $r['age'];
        if ($r['age'] < 0 || $r['agecrack'] < 0 || isset($input['hb']) && $input['hb'] < 0) {
            throw new InvalidArgumentException('Дата или твёрдость SCC некорректна.');
        }
        $r['Nc'] = (new SccInspectionModel)->combine($input['N'] ?? []);
        $r['eff'] = null;
        foreach (['A', 'B', 'C', 'D'] as $category) {
            if ($r['Nc'][$category] > 0) {
                $r['eff'] = $category;
                break;
            }
        }
        $r['nEff'] = $r['eff'] ? $r['Nc'][$r['eff']] : 0;
        $r['FOM'] = 1;
        if ($monitoring && ($input['kp'] || $input['hp'])) {
            $key = $input['kp'] && $input['hp'] ? 'both' : ($input['kp'] ? 'kp' : 'hp');
            if (! isset($tables['fom'][$key])) {
                return $this->blocked($kind, ['fom.'.$key]);
            }
            $r['FOM'] = $tables['fom'][$key];
            if (! is_numeric($r['FOM']) || ! is_finite((float) $r['FOM']) || $r['FOM'] <= 0) {
                throw new InvalidArgumentException('Некорректный коэффициент мониторинга SCC.');
            }
        }
        $ids = [];
        $knownWeight = 0.0;
        foreach ($input['flows'] as $index => $flow) {
            $fid = $flow['fid'] ?? null;
            if (! is_string($fid) || $fid === '' || isset($ids[$fid]) || ! isset($flow['act']) || ! is_bool($flow['act'])) {
                throw new InvalidArgumentException('Потоки SCC требуют уникального ID и явного признака активности.');
            }
            $ids[$fid] = true;
            foreach (['w', 'ph', 'h2s', 'pH2S', 'cn'] as $key) {
                if (isset($flow[$key]) && (! is_numeric($flow[$key]) || ! is_finite((float) $flow[$key]) || $flow[$key] < 0)) {
                    throw new InvalidArgumentException('Некорректное значение потока SCC: '.$key);
                }
            }
            if (isset($flow['w'])) {
                if ($flow['w'] > 100) {
                    throw new InvalidArgumentException('Доля времени потока должна быть от 0 до 100 %.');
                } $knownWeight += $flow['w'];
            }
            if (isset($flow['ph']) && $flow['ph'] > 14) {
                throw new InvalidArgumentException('pH должен быть от 0 до 14.');
            }
            $o = ['fid' => $fid, 'name' => $flow['name'] ?? $fid, 'w' => $flow['w'] ?? null, 'wDef' => false, 'act' => $flow['act']];
            if (! $flow['act']) {
                $o += ['susc' => '—', 'DF' => 0, 'SVI' => 0, 'DfB' => 0];
                $r['flows'][] = $o;

                continue;
            }
            if (! isset($flow['w'])) {
                $missing[] = 'flows.'.$fid.'.w';
            }
            $severity = null;
            if ($environment) {
                $keys = ['pH2S'];
                if (! isset($flow['pH2S']) || $flow['pH2S'] >= 0.000345) {
                    $keys = [...$keys, 'ph', 'h2s'];
                }
                foreach ($keys as $key) {
                    if (! isset($flow[$key])) {
                        $missing[] = 'flows.'.$fid.'.'.$key;
                    }
                }
                if (! array_diff($keys, array_keys(array_filter($flow, fn ($value) => $value !== null)))) {
                    if ($flow['pH2S'] < 0.000345) {
                        $severity = 'отсутствует';
                        $why = 'pH₂S < 0,000345 МПа';
                    } else {
                        $column = $flow['h2s'] < 50 ? 'c1' : ($flow['h2s'] < 1000 ? 'c2' : ($flow['h2s'] <= 10000 ? 'c3' : 'c4'));
                        foreach ($tables[$kind === 'ssc' ? 'sev' : 'sev92'] ?? [] as $row) {
                            if (($row['lo'] === null || $flow['ph'] >= $row['lo']) && ($row['hi'] === null || $flow['ph'] < $row['hi'])) {
                                $severity = $row[$column] ?? null;
                                break;
                            }
                        }
                        $why = 'pH '.str_replace('.', ',', (string) $flow['ph']).', H₂S '.str_replace('.', ',', (string) $flow['h2s']).' ppm';
                    }
                    $o['sev'] = $severity;
                    $o['sevWhy'] = $why;
                } else {
                    $o['sev'] = null;
                    $o['sevWhy'] = '';
                }
            }
            $base = null;
            if ($environment && $severity === 'отсутствует') {
                $base = 'отсутствует';
            } elseif (! $input['crkLast']) {
                $materialKeys = ['pwht'];
                if (in_array($kind, ['ssc', 'hschf'], true)) {
                    $materialKeys[] = 'hb';
                } else {
                    $materialKeys[] = 'seam';
                    if (! ($input['seam'] ?? false)) {
                        $materialKeys[] = 'sHigh';
                    }
                }
                foreach ($materialKeys as $key) {
                    if (! isset($input[$key])) {
                        $missing[] = $key;
                    }
                }
            }
            if ($base === null && isset($input['pwht'])) {
                $pw = $input['pwht'] ? 1 : 0;
                $hb = isset($input['hb']) ? ($input['hb'] < 200 ? 0 : ($input['hb'] <= 237 ? 1 : 2)) : null;
                if ($kind === 'ssc' && $severity !== null && $hb !== null) {
                    foreach ($tables['s103'] ?? [] as $row) {
                        if ($row['sev'] === $severity) {
                            $base = $row[$pw ? 'pw' : 'aw'][$hb] ?? null;
                            break;
                        }
                    }
                } elseif ($kind === 'hich2s' && $severity !== null && isset($input['seam']) && ($input['seam'] || isset($input['sHigh']))) {
                    foreach ($tables['s93'] ?? [] as $row) {
                        if ($row['sev'] === $severity) {
                            $base = $row[$input['seam'] ? 'sm' : ($input['sHigh'] ? 'hs' : 'ls')][$pw] ?? null;
                            break;
                        }
                    }
                } elseif ($kind === 'hichf' && isset($input['seam']) && ($input['seam'] || isset($input['sHigh']))) {
                    $base = $tables['s62'][$pw]['v'][$input['seam'] ? 2 : ($input['sHigh'] ? 0 : 1)] ?? null;
                } elseif ($kind === 'hschf' && $hb !== null) {
                    $base = $tables['s72'][$pw]['v'][$hb] ?? null;
                }
            }
            $o['base'] = $base;
            $o['cnUp'] = false;
            $susceptibility = $base;
            if ($environment && $base !== null && $base !== 'отсутствует' && isset($flow['ph'],$flow['h2s']) && $flow['ph'] > 7.6 && $flow['h2s'] > 1) {
                if (! isset($flow['cn']) && $base !== 'высокая' && ! $input['crkLast']) {
                    $missing[] = 'flows.'.$fid.'.cn';
                }
                if (isset($flow['cn']) && $flow['cn'] > 20) {
                    $level = array_search($base, self::LEVELS, true);
                    $susceptibility = $level === false ? null : self::LEVELS[min(3, $level + 1)];
                    $o['cnUp'] = true;
                }
            }
            $o['susc'] = $input['crkLast'] ? 'высокая' : $susceptibility;
            $o['SVI'] = null;
            foreach ($tables['svi'] ?? [] as $row) {
                if ($row['s'] === $o['susc']) {
                    $o['SVI'] = $row[$kind === 'hschf' ? 'hsc' : $kind] ?? null;
                    break;
                }
            }
            if ($o['SVI'] === null) {
                $missing[] = 'flows.'.$fid.'.susceptibility';
                $o['DfB'] = null;
                $o['DF'] = null;
            } else {
                $inspection = (new SccInspectionModel)->describe($input['N'] ?? [], (float) $o['SVI'], $tables['t13'] ?? []);
                $o['DfB'] = $inspection['DfB'];
                $o['DF'] = $o['DfB'] === null ? null : min($o['DfB'] * pow(max($r['agecrack'], 1), 1.1) / $r['FOM'], 5000);
                if ($o['DF'] === null) {
                    $missing[] = 'flows.'.$fid.'.damage_table_row';
                }
            }
            $r['flows'][] = $o;
        }
        if ($knownWeight > 100 + 1e-8) {
            throw new InvalidArgumentException('Сумма долей времени потоков превышает 100 %.');
        }
        $r['DF'] = null;
        if (! $missing) {
            $r['DF'] = 0.0;
            foreach ($r['flows'] as $flow) {
                if ($flow['act']) {
                    $r['DF'] += $flow['w'] / 100 * $flow['DF'];
                }
            }
        }

        return ['method' => self::VERSION, 'kind' => $kind, 'ready' => $missing === [], 'missing' => array_values(array_unique($missing)), 'res' => $r,
            'steps' => [['key' => 'flows', 'label' => 'Восприимчивость и DF по потокам', 'value' => $r['flows'], 'unit' => '—'], ['key' => 'agecrack', 'label' => 'Время с инспекции без трещин', 'value' => $r['agecrack'], 'unit' => 'лет'], ['key' => 'Nc', 'label' => 'Эквивалентные инспекции', 'value' => $r['Nc'], 'unit' => 'шт.'], ['key' => 'FOM', 'label' => 'Оперативный мониторинг', 'value' => $r['FOM'], 'unit' => '—'], ['key' => 'DF', 'label' => 'Взвешенный фактор повреждения', 'value' => $r['DF'], 'unit' => '—']]];
    }

    private function blocked(string $kind, array $missing): array
    {
        return ['method' => self::VERSION, 'kind' => $kind, 'ready' => false, 'missing' => $missing, 'res' => ['DF' => null], 'steps' => []];
    }
}
