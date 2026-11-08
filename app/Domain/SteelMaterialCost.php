<?php

namespace App\Domain;

class SteelMaterialCost
{
    /** Uses effective composition, never the historical rbi_matcost field. */
    public function describe(array $values, array $rows): array
    {
        $sub = mb_strtolower($values['subtype'] ?? '');
        $refine = mb_strtolower($values['refine'] ?? '');
        $composition = collect($values['composition'] ?? [])->keyBy('el');
        $element = fn ($el) => $composition[$el]['max'] ?? $composition[$el]['min'] ?? 0;
        $cr = $element('Cr');
        $mo = $element('Mo');
        $ni = $element('Ni');
        $warning = false;
        $needed = [];
        if ($sub === 'углеродистая') {
            [$name, $reason] = ['Carbon steel', 'Подтип «углеродистая».'];
        } elseif ($sub === 'низколегированная' && $refine !== 'хроммолибденовая' && ! ($mo > 0)) {
            [$name, $reason] = ['— (дополнено)', 'Низколегированная без Cr-Mo; дополнение адаптированной таблицы.'];
            $needed = ['Mo'];
        } elseif ($refine === 'хроммолибденовая' || $sub === 'низколегированная') {
            $name = match (true) {
                $cr <= 1.5 => '1.25Cr-0.5Mo', $cr <= 3 => '2.25Cr-1Mo', $cr <= 6 => '5Cr-0.5Mo', $cr <= 8 => '7Cr-0.5Mo', default => '9Cr-1Mo'
            };
            $reason = 'Cr-Mo: выбор по верхней границе Cr; Mo-сталь / 0,5Cr — ближайший аналог 1,25Cr-0,5Mo.';
            $needed = ['Cr'];
        } elseif ($sub === 'аустенитная нержавеющая') {
            $name = $mo >= 1.5 || $cr >= 22 || $ni >= 15 ? '316 SS' : '304 SS';
            $warning = $mo < 1.5 && ($cr >= 22 || $ni >= 15);
            $reason = $mo >= 1.5 ? 'Аустенитная с Mo (тип 316).' : ($warning ? 'Тип 309/310: точного аналога нет, принят 316.' : 'Аустенитная без Mo (тип 304/321/347).');
            $needed = ['Cr', 'Mo', 'Ni'];
        } elseif ($sub === 'мартенситная нержавеющая' || $refine === 'мартенситная') {
            [$name, $reason] = ['410 SS', 'Мартенситная (тип 410).'];
        } elseif ($sub === 'ферритная нержавеющая') {
            [$name, $reason] = ['405 SS', $cr <= 14.5 ? 'Ферритная 12–14 % Cr (тип 405/410S).' : 'Тип 430/446: точного аналога нет, принят 405.'];
            $warning = $cr > 14.5;
            $needed = ['Cr'];
        } elseif ($sub === 'дуплексная нержавеющая') {
            [$name, $reason, $warning] = ['316 SS', 'Дуплексная: точного аналога нет, принят 316.', true];
        } else {
            [$name, $reason, $warning] = [null, 'Подтип не задан или не поддерживается: присвойте в марочнике.', true];
        }
        $assumptions = array_values(array_filter($needed, fn ($el) => ! isset($composition[$el]['max']) && ! isset($composition[$el]['min'])));
        $row = collect($rows)->first(fn ($row) => $row['english_name'] === $name && $row['material_group'] === 'base');
        $factor = $row === null ? null : array_replace($row['base_values'], $row['overrides'])['factor'] ?? null;

        $manual = array_key_exists('accepted_cost', $values);

        return ['factor' => $manual ? $values['accepted_cost'] : $factor, 'automatic_factor' => $factor, 'manual' => $manual, 'reference_id' => $row['id'] ?? null, 'reference_version' => $row['version'] ?? null,
            'reference_base_version' => $row['base_version'] ?? null, 'analog' => $row['label'] ?? null, 'reason' => $values['cost_reason'] ?? $reason,
            'warning' => $warning || $assumptions !== [] || ($manual ? $values['accepted_cost'] : $factor) === null,
            'assumptions' => array_map(fn ($el) => $el.': отсутствующий состав принят за 0 только для выбора аналога по прежней логике; уточните состав.', $assumptions)];
    }
}
