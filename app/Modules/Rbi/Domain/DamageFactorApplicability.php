<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

/** First input-builder step: screening applicability is independent of numerical DF readiness. */
final class DamageFactorApplicability
{
    private const EXTERNAL = ['ext' => 'extf', 'cui' => 'cuif', 'ecl' => 'extcl', 'ccl' => 'cuicl'];

    private const THROUGH = ['bf', 'lase', 'e885', 'sigma', 'mfat'];

    public function resolve(array $element, ?string $typeCode): array
    {
        if (! isset($element['active']) || ! is_bool($element['active']) || ! is_array($element['mechanisms'] ?? null) || ! array_is_list($element['mechanisms'])) {
            throw new InvalidArgumentException('Требуется явный статус физического элемента и список МД.');
        }
        $excluded = ! $element['active'] ? 'inactive_element' : ($typeCode === 'flange' ? 'ordinary_flange' : null);
        $byId = [];
        foreach ($element['mechanisms'] as $mechanism) {
            if (isset($byId[$mechanism['id']])) {
                throw new InvalidArgumentException('Механизм повторяется в компонентном скрининге.');
            }
            $byId[$mechanism['id']] = $mechanism;
        }
        $thin = array_column(array_filter($element['mechanisms'], fn ($row) => $row['group'] === '2.B' && ! $row['component_only']), 'id');
        $factors = [];
        foreach (DamageFactorAggregator::MECHANISMS as $factor) {
            $side = isset(self::EXTERNAL[$factor]) ? 'external' : (in_array($factor, self::THROUGH, true) ? 'through' : 'internal');
            $ids = $factor === 'thin' ? $thin : [self::EXTERNAL[$factor] ?? $factor];
            $sources = [];
            foreach ($ids as $id) {
                $matches = array_filter($byId[$id]['sides'] ?? [], fn ($row) => $row['side'] === $side);
                if (count($matches) > 1) {
                    throw new InvalidArgumentException('Физическая сторона механизма повторяется.');
                }
                $source = array_values($matches)[0] ?? null;
                $state = $source['state'] ?? 'potential';
                if (! in_array($state, ['active', 'potential', 'inactive'], true)) {
                    throw new InvalidArgumentException('Неизвестный статус компонентного скрининга.');
                }
                $sources[] = ['mechanism' => $id, 'side' => $side, 'state' => $state];
            }
            $states = array_column($sources, 'state');
            $state = $excluded !== null ? 'inactive' : (in_array('active', $states, true) ? 'active' : (! $states || in_array('potential', $states, true) ? 'potential' : 'inactive'));
            $factors[$factor] = ['state' => $state, 'sources' => $sources, 'excluded_reason' => $excluded];
        }

        return ['factors' => $factors, 'active' => array_keys(array_filter($factors, fn ($row) => $row['state'] === 'active')),
            'potential' => array_keys(array_filter($factors, fn ($row) => $row['state'] === 'potential')),
            'applicability_known' => ! array_filter($factors, fn ($row) => $row['state'] === 'potential'), 'excluded_reason' => $excluded];
    }
}
