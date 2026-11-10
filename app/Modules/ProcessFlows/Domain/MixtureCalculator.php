<?php

namespace App\Modules\ProcessFlows\Domain;

class MixtureCalculator
{
    public const VERSION = 'mixture-v1.2';

    /** @param array $components Resolved catalog values are supplied explicitly; no hidden defaults. */
    public function calculate(string $basis, array $components, array $conditions = []): array
    {
        $conditions = $this->conditions($conditions);
        $main = array_values(array_filter($components, fn ($row) => $row['role'] === 'main'));
        $inputSum = array_sum(array_map(fn ($row) => $row['amount'] ?? 0, $main));
        $amountsKnown = $main !== [] && collect($main)->every(fn ($row) => $row['amount'] !== null);
        $mwKnown = $main !== [] && collect($main)->every(fn ($row) => $this->number($row['substance_values']['mw'] ?? null, true) !== null);
        $denominator = $amountsKnown && $mwKnown ? array_sum(array_map(fn ($row) => $basis === 'mole' ? $row['amount'] * $row['substance_values']['mw'] : $row['amount'] / $row['substance_values']['mw'], $main)) : null;
        if ($denominator !== null && ! is_finite($denominator)) {
            $denominator = null;
        }
        $complete = $amountsKnown && abs($inputSum - 100) <= 0.01;
        $rows = [];
        foreach ($main as $row) {
            $values = $row['substance_values'];
            $mw = $this->number($values['mw'] ?? null, true);
            $mass = $basis === 'mass' ? $row['amount'] : ($denominator > 0 ? ($row['amount'] * $mw / $denominator) * 100 : null);
            $mole = $basis === 'mole' ? $row['amount'] : ($denominator > 0 ? ($row['amount'] / $mw / $denominator) * 100 : null);
            $flags = [];
            foreach (['flammable', 'toxic', 'nfnt', 'inert'] as $key) {
                $flags[$key] = is_bool($values[$key] ?? null) ? $values[$key] : null;
            }
            $rows[] = ['id' => $row['id'], 'substance_id' => $row['substance_id'], 'substance_version' => $row['substance_version'], 'name' => $values['name'],
                'mass_percent' => $mass, 'mole_percent' => $mole, 'mw' => $mw, 'nbp' => $this->number($values['nbp'] ?? null), 'density' => $this->number($values['dens'] ?? null, true), 'flags' => $flags];
        }
        $properties = ['molecular_weight' => null, 'boiling_point' => null, 'liquid_density' => null, 'gas_density' => null, 'density' => null];
        if ($complete && $mwKnown && $denominator > 0) {
            $properties['molecular_weight'] = $basis === 'mass' ? 100 / $denominator : $denominator / 100;
            if (collect($rows)->every(fn ($row) => $row['nbp'] !== null)) {
                $nbp = array_sum(array_map(fn ($row) => ($row['mole_percent'] / 100) * $row['nbp'], $rows));
                $properties['boiling_point'] = is_finite($nbp) ? $nbp : null;
            }
            if (collect($rows)->every(fn ($row) => $row['density'] !== null)) {
                $volume = array_sum(array_map(fn ($row) => $row['mass_percent'] / 100 / $row['density'], $rows));
                if ($volume > 0 && is_finite($volume) && is_finite(1 / $volume)) {
                    $properties['liquid_density'] = 1 / $volume;
                }
            }
        }
        $densityMethod = null;
        if ($conditions['phase'] === 'gas') {
            $pressure = $conditions['pressure'];
            $temperature = $conditions['temperature'];
            if ($properties['molecular_weight'] !== null && $pressure !== null && $pressure > -0.101325 && $temperature !== null && $temperature > -273.15) {
                // Same explicit atmospheric reference and R as the old comparison calculator; Z = 1.
                $density = (($pressure + 0.101325) * 1e6) * ($properties['molecular_weight'] / 1000) / (8.314 * ($temperature + 273.15));
                if (is_finite($density) && $density > 0) {
                    $properties['gas_density'] = $properties['density'] = $density;
                    $densityMethod = 'ideal_gas';
                }
            }
        } elseif ($conditions['phase'] === 'liquid') {
            $properties['density'] = $properties['liquid_density'];
            $densityMethod = $properties['density'] !== null ? 'catalog_liquid' : null;
        }
        $criteria = [];
        foreach (['flammable', 'toxic', 'nfnt', 'inert', 'unknown'] as $key) {
            $members = array_values(array_filter($rows, fn ($row) => $key === 'unknown' ? in_array(null, $row['flags'], true) : $row['flags'][$key] === true));
            $criteria[] = ['criterion' => $key, 'count' => count($members), 'mass_percent' => $main === [] || collect($members)->contains(fn ($row) => $row['mass_percent'] === null) ? null : array_sum(array_column($members, 'mass_percent')),
                'mole_percent' => $main === [] || collect($members)->contains(fn ($row) => $row['mole_percent'] === null) ? null : array_sum(array_column($members, 'mole_percent')), 'components' => array_column($members, 'name')];
        }
        $warnings = [];
        if (! $amountsKnown) {
            $warnings[] = 'Основной состав не заполнен полностью.';
        }
        if ($amountsKnown && ! $complete) {
            $warnings[] = 'Сумма исходного состава отличается от 100 %: '.$inputSum.' %.';
        }
        if (! $mwKnown) {
            $warnings[] = 'Нет положительной MW для всех компонентов; пересчёт массовой/мольной базы и свойства смеси не определены.';
        }
        if ($amountsKnown && $mwKnown && $denominator === null) {
            $warnings[] = 'Исходные данные выходят за численный диапазон; свойства смеси не рассчитаны.';
        }
        if (collect($main)->contains(fn ($row) => $row['plus'] ?? false)) {
            $warnings[] = 'Для строк «и выше» используются свойства выбранного каталожного представителя; состав тяжёлого остатка отдельно не определяется.';
        }
        if ($properties['boiling_point'] === null) {
            $warnings[] = 'Нормальная температура кипения смеси не определена.';
        }
        if ($conditions['phase'] === 'gas') {
            $warnings[] = $densityMethod === 'ideal_gas'
                ? 'Плотность газа: ρ = (Pизб + 0.101325)·10⁶·(MW/1000) / (8.314·(T + 273.15)). Допущение идеального газа, Z = 1; Pизб в МПа, T в °C.'
                : 'Плотность газа не определена: нужны MW полного состава, избыточное давление выше −0.101325 МПа и температура выше −273.15 °C.';
        } elseif ($conditions['phase'] === 'liquid') {
            $warnings[] = $densityMethod === 'catalog_liquid'
                ? 'Плотность рассчитана по каталожным ρL; поправки на рабочие температуру и давление этим расчётом не выполняются.'
                : 'Плотность жидкой смеси не определена: нужны плотности всех компонентов полного состава.';
        } else {
            $warnings[] = 'Плотность потока не определена: задайте газовую или жидкую фазу. Для двухфазной среды и твёрдого вещества этот метод неприменим.';
        }
        $signatureRows = array_map(function ($row) {
            $used = [];
            foreach (['mw', 'nbp', 'dens', 'flammable', 'toxic', 'nfnt', 'inert'] as $key) {
                $used[$key] = $row['substance_values'][$key] ?? null;
            }

            return ['id' => $row['id'], 'substance_id' => $row['substance_id'], 'substance_version' => $row['substance_version'], 'amount' => $row['amount'], 'plus' => $row['plus'] ?? false, 'properties' => $used];
        }, $main);
        usort($signatureRows, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return ['engine_version' => self::VERSION, 'signature' => hash('sha256', json_encode([self::VERSION, $basis, $signatureRows, $conditions], JSON_THROW_ON_ERROR)),
            'conditions' => $conditions, 'density_method' => $densityMethod,
            'input_basis' => $basis, 'input_sum' => $inputSum, 'complete' => $complete && $mwKnown && $denominator > 0, 'rows' => $rows, 'properties' => $properties, 'criteria' => $criteria, 'warnings' => $warnings];
    }

    /** Only conditions actually used by this engine affect its signature. */
    private function conditions(array $attributes): array
    {
        $phase = in_array($attributes['phase'] ?? null, ['gas', 'liquid', 'two_phase', 'solid'], true) ? $attributes['phase'] : null;

        return ['phase' => $phase,
            'pressure' => $phase === 'gas' ? $this->number($attributes['pressure'] ?? null) : null,
            'temperature' => $phase === 'gas' ? $this->number($attributes['temperature'] ?? null) : null];
    }

    private function number(mixed $value, bool $positive = false): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && (! $positive || $value > 0) ? (float) $value : null;
    }
}
