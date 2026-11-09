<?php

namespace App\Modules\Rbi\Domain;

use App\Modules\Rbi\Domain\Consequences\CoefficientTable;
use App\Modules\Rbi\Domain\Consequences\FinancialConsequences;
use App\Modules\Rbi\Domain\Consequences\FireConsequences;
use App\Modules\Rbi\Domain\Consequences\ReleaseModel;
use App\Modules\Rbi\Domain\Consequences\ToxicConsequences;
use InvalidArgumentException;

/** Level 1 CoF orchestrates independent release/fire/toxic/financial domain strategies. */
final class ConsequenceOfFailure
{
    public const VERSION = 'cof-v24.4-v1';

    public function calculate(array $input, array $tables): array
    {
        $missing = $this->validate($input, $tables);
        if ($missing) {
            return ['method' => self::VERSION, 'ready' => false, 'missing' => $missing, 'res' => null, 'steps' => []];
        }
        $release = (new ReleaseModel)->calculate($input);
        $fire = (new FireConsequences)->calculate($input, $release);
        $toxic = (new ToxicConsequences)->calculate($input, $release, $tables);
        $weights = $input['gff'];
        $total = array_sum($weights);
        $weighted = fn ($values) => array_sum(array_map(fn ($value, $index) => $value * $weights[$index], $values, array_keys($values))) / $total;
        $nfnt = [];
        $name = mb_strtolower($input['fluid']['name']);
        foreach ($release['diameters'] as $index => $diameter) {
            $rate = $release['rate'][$index];
            $mass = $release['mass'][$index];
            $value = 0;
            if ($name === 'пар') {
                $instant = $release['types'][$index] === 'мгновенный' ? 1 : min($rate / 25.2, 1);
                $value = $diameter ? (($mass > 0 ? 9.744 * pow($mass, 0.6384) : 0) * $instant + 0.123 * $rate * (1 - $instant)) : 0;
            } elseif (str_contains($name, 'кислота') || str_contains($name, 'каустик')) {
                [$a,$b] = CoefficientTable::pair($input['cf49'], 1, 'жидкость');
                $value = $diameter && $rate > 0 && $a !== null ? 0.2 * $a * pow($rate, $b) : 0;
            }
            $nfnt[] = $value;
        }
        $component = $weighted($fire['component']);
        $fireInjury = $weighted($fire['injury']);
        $tox = $weighted($toxic['maximum']);
        $nonFlammable = $weighted($nfnt);
        $injury = max($fireInjury, $tox, $nonFlammable);
        $financial = (new FinancialConsequences)->calculate($input, $release, $component, $injury);
        $result = ['CA' => max($component, $injury), 'CAc' => $component, 'CAi' => $injury, 'FL' => $component, 'TX' => $tox, 'NF' => $nonFlammable];
        foreach (['FC', 'FCc', 'FCa', 'FCp', 'FCi', 'FCe'] as $key) {
            $result[$key] = $financial[$key];
        }
        foreach ($result as $value) {
            if (! is_finite($value) || $value < 0) {
                throw new InvalidArgumentException('Переполнение или отрицательный результат CoF.');
            }
        }

        return ['method' => self::VERSION, 'ready' => true, 'missing' => [], 'res' => $result, 'steps' => [
            ['key' => 'release', 'value' => $release], ['key' => 'fire', 'value' => $fire], ['key' => 'toxic', 'value' => $toxic], ['key' => 'nfnt', 'value' => $nfnt], ['key' => 'financial', 'value' => $financial]],
            'assumptions' => ['Двухфазная среда рассчитывается как жидкость.', 'Табличная длительность токсичного выброса округляется вверх консервативно.', 'Курс 100 RUB/USD применяется только в формуле простоя 3.62 для совместимости версии метода.']];
    }

    private function validate(array $input, array $tables): array
    {
        $missing = [];
        $numeric = function (array $source, string $key, string $prefix = '', ?float $minimum = 0, ?float $maximum = null) use (&$missing): void {
            $path = $prefix.$key;
            if (! isset($source[$key])) {
                $missing[] = $path;

                return;
            }
            $value = $source[$key];
            if ((! is_int($value) && ! is_float($value)) || ! is_finite($value) || $minimum !== null && $value < $minimum || $maximum !== null && $value > $maximum) {
                throw new InvalidArgumentException('Некорректный числовой вход CoF: '.$path);
            }
        };
        foreach (['fluid', 'cost', 'tox'] as $key) {
            if (! isset($input[$key])) {
                $missing[] = $key;
            } elseif (! is_array($input[$key])) {
                throw new InvalidArgumentException('Ожидается объект CoF: '.$key);
            }
        }
        $fluid = $input['fluid'] ?? [];
        $cost = $input['cost'] ?? [];
        foreach (['P', 'Vk', 'Vgz', 'matcost'] as $key) {
            $numeric($input, $key);
        }
        $numeric($input, 'T', '', -273.149999);
        $numeric($input, 'proddecrease', '', 0, 100);
        foreach (['factdi', 'factmit', 'flamFrac'] as $key) {
            $numeric($input, $key, '', 0, 1);
        }
        foreach (['costfactor', 'outagemult', 'equipcost', 'prodcost', 'popdens', 'injcost', 'envcost'] as $key) {
            $numeric($cost, $key, 'cost.');
        }
        foreach (['phase', 'pipeRule'] as $key) {
            if (! array_key_exists($key, $input)) {
                $missing[] = $key;
            }
        }
        if (isset($input['phase']) && ! in_array($input['phase'], ['газ', 'пар', 'жидкость', 'двухфазная', 'газ + жидкость'], true)) {
            throw new InvalidArgumentException('Неизвестная фаза CoF.');
        }
        if (isset($input['pipeRule']) && ! is_bool($input['pipeRule'])) {
            throw new InvalidArgumentException('Правило отверстий должно быть задано явно.');
        }
        if ($input['pipeRule'] ?? false) {
            $numeric($input, 'D', '', 0.000001);
        }
        foreach (['name', 'phaseNC'] as $key) {
            if (! isset($fluid[$key]) || ! is_string($fluid[$key]) || $fluid[$key] === '') {
                $missing[] = 'fluid.'.$key;
            }
        }
        if (isset($fluid['phaseNC']) && ! in_array($fluid['phaseNC'], ['газ', 'жидкость'], true)) {
            throw new InvalidArgumentException('Неизвестная нормальная фаза среды.');
        }
        $gas = in_array($input['phase'] ?? null, ['газ', 'пар'], true);
        if ($gas) {
            $numeric($fluid, 'k', 'fluid.', 1.000001);
            $numeric($fluid, 'mw', 'fluid.', 0.000001);
            $numeric($input, 'rhoG', '', 0.000001);
        } else {
            $numeric($fluid, 'rhoL', 'fluid.', 0.000001);
            if (($fluid['phaseNC'] ?? null) === 'газ') {
                $numeric($fluid, 'nbp', 'fluid.', null);
            }
        }
        $liquidRelease = ! $gas && ! (($fluid['phaseNC'] ?? null) === 'газ' && isset($fluid['nbp']) && $fluid['nbp'] <= 26);
        if ($liquidRelease) {
            $numeric($fluid, 'fe', 'fluid.', 0, 1);
        }
        foreach (['gff', 'ldmax', 'holecost', 'outage'] as $key) {
            if (! isset($input[$key])) {
                $missing[] = $key;

                continue;
            }
            if (! is_array($input[$key]) || ! array_is_list($input[$key]) || count($input[$key]) !== 4) {
                throw new InvalidArgumentException('Требуются четыре значения CoF: '.$key);
            }
            foreach ($input[$key] as $index => $value) {
                if ($key === 'outage' && $value === 'N') {
                    continue;
                }$numeric($input[$key], $index, $key.'.', $key === 'ldmax' ? 0.000001 : 0);
            }
        }
        if (isset($input['gff']) && array_sum($input['gff']) <= 0) {
            throw new InvalidArgumentException('Суммарная частота отказов должна быть положительной.');
        }
        if (isset($input['gffTot'])) {
            $numeric($input, 'gffTot', '', 0.000001);
            if (isset($input['gff']) && abs($input['gffTot'] - array_sum($input['gff'])) > max(1e-15, array_sum($input['gff']) * 1e-10)) {
                throw new InvalidArgumentException('Суммарная частота не совпадает с частотами отверстий.');
            }
        }
        foreach ($input['tox'] ?? [] as $key => $value) {
            if (! in_array($key, ToxicConsequences::SUBSTANCES, true)) {
                throw new InvalidArgumentException('Неизвестное токсичное вещество: '.$key);
            }$numeric($input['tox'], $key, 'tox.', 0, 1);
        }
        if (array_sum($input['tox'] ?? []) > 1 + 1e-9) {
            throw new InvalidArgumentException('Сумма токсичных массовых долей превышает единицу.');
        }
        if (($fluid['inert'] ?? false) && (($input['flamFrac'] ?? 0) > 0 || array_sum($input['tox'] ?? []) > 0)) {
            throw new InvalidArgumentException('Инертная среда противоречит опасным массовым долям.');
        }
        $needsFire = isset($input['flamFrac']) && $input['flamFrac'] > 0;
        $needsCaustic = isset($fluid['name']) && (str_contains(mb_strtolower($fluid['name']), 'кислота') || str_contains(mb_strtolower($fluid['name']), 'каустик'));
        if ($needsFire) {
            $numeric($fluid, 'aitC', 'fluid.', -273.149999);
            if (! isset($fluid['type'])) {
                $missing[] = 'fluid.type';
            } elseif (! in_array($fluid['type'], [0, 1, '0', '1'], true)) {
                throw new InvalidArgumentException('Неизвестный тип горючей среды.');
            }
        }
        $phase = $liquidRelease ? 'жидкость' : 'газ';
        foreach (['cf48', 'cf49'] as $key) {
            if (! $needsFire && ! ($needsCaustic && $key === 'cf49')) {
                continue;
            }
            for ($row = 1; $row <= ($needsFire ? 4 : 1); $row++) {
                [$a,$b] = CoefficientTable::pair($input[$key] ?? [], $row, $needsFire ? $phase : 'жидкость');
                if ($a === null || $b === null) {
                    $missing[] = $key.'.'.$row.'.'.$phase;
                } elseif ($a < 0) {
                    throw new InvalidArgumentException('Отрицательный коэффициент площади.');
                }
            }
        }
        foreach ($input['tox'] ?? [] as $substance => $fraction) {
            if ($fraction > 0) {
                $key = in_array($substance, ['HF', 'H2S'], true) ? 'cofToxic411' : (in_array($substance, ['NH3', 'Cl2'], true) ? 'cofToxic412' : 'cofToxic413');
                if (empty($tables[$key])) {
                    $missing[] = 'tables.'.$key;
                }
            }
        }

        return array_values(array_unique($missing));
    }
}
