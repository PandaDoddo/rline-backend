<?php

namespace App\Modules\Rbi\Domain;

use App\Modules\Rbi\Domain\Susceptibility\AmineRule;
use App\Modules\Rbi\Domain\Susceptibility\CarbonateRule;
use App\Modules\Rbi\Domain\Susceptibility\CausticRule;
use App\Modules\Rbi\Domain\Susceptibility\HydrogenAttackRule;
use App\Modules\Rbi\Domain\Susceptibility\InternalChlorideRule;
use App\Modules\Rbi\Domain\Susceptibility\PolythionicRule;
use App\Modules\Rbi\Domain\Susceptibility\RuleInputs;
use App\Modules\Rbi\Domain\Susceptibility\SusceptibilityResult;
use InvalidArgumentException;

/** Selects a susceptibility strategy, then applies shared inspection and operating rules. */
final class EnvironmentalCrackingDamageFactor
{
    public const VERSION = 'df-environmental-cracking-v1';

    private const COMPONENT_FIELDS = ['pwht', 'T', 'heat', 'steam', 'alloy', 'thist', 'prot', 'hDmg', 'hRepl', 'grp'];

    public function calculate(array $input, string $kind, array $tables): array
    {
        $rule = match ($kind) {
            'acscc' => new CarbonateRule,'aminescc' => new AmineRule,'causticscc' => new CausticRule,'clscc' => new InternalChlorideRule,'pascc' => new PolythionicRule,'htha' => new HydrogenAttackRule,default => throw new InvalidArgumentException('Неизвестное семейство растрескивания.')
        };
        $hydrogen = $kind === 'htha';
        $noAge = $kind === 'pascc';
        if (! is_array($input['flows'] ?? null)) {
            return $this->blocked($kind, ['flows']);
        }
        $fractions = new OperatingFractions;
        $missing = $fractions->validate($input['flows']);
        $cracks = $hydrogen ? false : RuleInputs::flag($input, 'crk');
        if ($cracks === null) {
            $missing[] = 'crk';
        }
        $analysis = RuleInputs::number($input, 'tAn');
        $commissioned = RuleInputs::number($input, 'tIn');
        $inspection = RuleInputs::number($input, 'tCrk');
        if (! $hydrogen && ! $noAge) {
            if ($analysis === null) {
                $missing[] = 'tAn';
            } if ($commissioned === null && $inspection === null) {
                $missing[] = 'tIn';
            }
        }
        $r = ['kind' => $kind, 'flows' => []];
        if (! $hydrogen) {
            $r['age'] = $analysis !== null && $commissioned !== null ? $analysis - $commissioned : null;
            $r['agecrack'] = $analysis !== null && $inspection !== null ? $analysis - $inspection : $r['age'];
            if ($r['age'] !== null && $r['age'] < 0 || $r['agecrack'] !== null && $r['agecrack'] < 0) {
                throw new InvalidArgumentException('Даты растрескивания не согласованы.');
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
        }
        foreach ($input['flows'] as $flow) {
            $result = ['fid' => $flow['fid'], 'name' => $flow['name'] ?? $flow['fid'], 'w' => $flow['w'] ?? null, 'wDef' => $flow['wDef'] ?? false, 'act' => $flow['act']];
            if (! $flow['act']) {
                $result += ['susc' => '—', 'DF' => 0];
                if (! $hydrogen) {
                    $result += ['SVI' => 0, 'DfB' => 0];
                } $r['flows'][] = $result;

                continue;
            }
            $susceptibility = $cracks ? new SusceptibilityResult('высокая', 'трещины выявлены → высокая') : $rule->evaluate($input, $flow, $tables);
            $result['susc'] = $susceptibility->value;
            $result['why'] = $susceptibility->reason;
            foreach ($susceptibility->missing as $key) {
                $missing[] = in_array($key, self::COMPONENT_FIELDS, true) ? $key : 'flows.'.$flow['fid'].'.'.$key;
            }
            if ($hydrogen) {
                $result['DF'] = null;
                foreach ($tables['e22'] ?? [] as $row) {
                    if ($row['s'] === $result['susc']) {
                        $result['DF'] = $row['df'];
                        break;
                    }
                }if ($result['DF'] === null) {
                    $missing[] = 'flows.'.$flow['fid'].'.hydrogen_damage_table';
                }
            } else {
                $state = $result['susc'] === 'нет' ? 'отсутствует' : $result['susc'];
                $column = ['aminescc' => 'amine', 'causticscc' => 'caustic'][$kind] ?? $kind;
                $result['SVI'] = null;
                foreach ($tables['svi'] ?? [] as $row) {
                    if ($row['s'] === $state) {
                        $result['SVI'] = $row[$column] ?? null;
                        break;
                    }
                }
                $result['DfB'] = null;
                if ($result['SVI'] !== null) {
                    $result['DfB'] = (new SccInspectionModel)->describe($input['N'] ?? [], (float) $result['SVI'], $tables['t13'] ?? [])['DfB'];
                }
                $multiplier = $noAge ? 1 : ($r['agecrack'] === null ? null : pow(max($r['agecrack'], 1), 1.1));
                $result['DF'] = $result['DfB'] !== null && $multiplier !== null ? min($result['DfB'] * $multiplier, 5000) : null;
                if ($result['DfB'] === null) {
                    $missing[] = 'flows.'.$flow['fid'].'.scc_damage_table';
                }
            }
            if (isset($result['DF']) && (! is_numeric($result['DF']) || ! is_finite((float) $result['DF']) || $result['DF'] < 0)) {
                throw new InvalidArgumentException('Некорректный результат таблицы DF.');
            }
            $r['flows'][] = $result;
        }
        if ($hydrogen) {
            $r['agecrack'] = null;
        }
        $r['DF'] = $missing ? null : $fractions->sum($r['flows']);

        return ['method' => self::VERSION, 'kind' => $kind, 'ready' => $missing === [], 'missing' => array_values(array_unique($missing)), 'res' => $r,
            'steps' => [['key' => 'flows', 'label' => 'Восприимчивость и факторы повреждения по потокам', 'value' => $r['flows'], 'unit' => '—'], ['key' => 'agecrack', 'label' => $noAge ? 'PASCC: без роста со временем' : 'Время с инспекции без трещин', 'value' => $r['agecrack'], 'unit' => 'лет'], ['key' => 'DF', 'label' => 'Взвешенный фактор повреждения', 'value' => $r['DF'], 'unit' => '—']]];
    }

    private function blocked(string $kind, array $missing): array
    {
        return ['method' => self::VERSION, 'kind' => $kind, 'ready' => false, 'missing' => $missing, 'res' => ['DF' => null], 'steps' => []];
    }
}
