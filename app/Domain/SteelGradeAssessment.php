<?php

namespace App\Domain;

use App\Models\MaterialCost;
use Illuminate\Validation\ValidationException;

class SteelGradeAssessment
{
    public function describe(array $values): array
    {
        $warnings = [];
        foreach ([['rm_min', 'rm_max'], ['re_min', 're_max'], ['hbBase_min', 'hbBase_max'], ['hbWeld_min', 'hbWeld_max'], ['matMin', 'matMax']] as [$min, $max]) {
            if (is_numeric($values[$min] ?? null) && is_numeric($values[$max] ?? null) && $values[$min] > $values[$max]) {
                $warnings[] = 'Исходный диапазон «'.config('steel-grades.fields.'.$min.'.label').'» противоречив; уточните источник.';
            }
        }
        if (is_numeric($values['rbi_jfac'] ?? null) || is_numeric($values['rbi_xbar'] ?? null)) {
            $warnings[] = 'J/X̄-факторы перенесены без предметной проверки; вопрос остаётся в BACKLOG.';
        }

        $cost = app(SteelMaterialCost::class)->describe($values, MaterialCost::all()->toArray());

        return ['warnings' => $warnings, 'accepted_matcost' => $cost['factor'], 'material_cost' => $cost,
            'matcost_notice' => 'matcost принят в справочнике марок; без ручной правки — по действующей таблице 4.16 и составу марки. Историческое значение карточки не применяется.',
            'stress_policy' => 'Исходные точки в °C / МПа. Пустые значения не заполняются; экстраполяция и расчёт напряжений этим API не выполняются.'];
    }

    public function validateChanged(array $values, array $changed): void
    {
        foreach ([['rm_min', 'rm_max'], ['re_min', 're_max'], ['hbBase_min', 'hbBase_max'], ['hbWeld_min', 'hbWeld_max'], ['matMin', 'matMax']] as [$min, $max]) {
            if (! array_key_exists($min, $changed) && ! array_key_exists($max, $changed)) {
                continue;
            }
            if (is_numeric($values[$min] ?? null) && is_numeric($values[$max] ?? null) && $values[$min] > $values[$max]) {
                throw ValidationException::withMessages(['values.'.$max => 'Максимум не может быть меньше минимума.']);
            }
        }
    }
}
