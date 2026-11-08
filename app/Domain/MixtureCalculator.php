<?php

namespace App\Domain;

class MixtureCalculator
{
    public const VERSION = 'mixture-v1.1';

    /** @param array $components Resolved catalog values are supplied explicitly; no hidden defaults. */
    public function calculate(string $basis, array $components): array
    {
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
        $properties = ['molecular_weight' => null, 'boiling_point' => null, 'liquid_density' => null];
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
        if ($properties['liquid_density'] === null) {
            $warnings[] = 'Плотность смеси не определена.';
        } else {
            $warnings[] = 'Плотность рассчитана по каталожным ρL; поправки на рабочие температуру и давление этим расчётом не выполняются.';
        }
        $signatureRows = array_map(function ($row) {
            $used = [];
            foreach (['mw', 'nbp', 'dens', 'flammable', 'toxic', 'nfnt', 'inert'] as $key) {
                $used[$key] = $row['substance_values'][$key] ?? null;
            }

            return ['id' => $row['id'], 'substance_id' => $row['substance_id'], 'substance_version' => $row['substance_version'], 'amount' => $row['amount'], 'plus' => $row['plus'] ?? false, 'properties' => $used];
        }, $main);
        usort($signatureRows, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return ['engine_version' => self::VERSION, 'signature' => hash('sha256', json_encode([self::VERSION, $basis, $signatureRows], JSON_THROW_ON_ERROR)),
            'input_basis' => $basis, 'input_sum' => $inputSum, 'complete' => $complete && $mwKnown && $denominator > 0, 'rows' => $rows, 'properties' => $properties, 'criteria' => $criteria, 'warnings' => $warnings];
    }

    private function number(mixed $value, bool $positive = false): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && (! $positive || $value > 0) ? (float) $value : null;
    }
}
