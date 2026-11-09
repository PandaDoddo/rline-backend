<?php

namespace App\Modules\ProcessFlows\Domain;

use App\Modules\Shared\Domain\ValueEquality;

class ProcessFlowHistory
{
    public function fields(): array
    {
        return config('process-flows.fields') + ['name' => ['label' => 'Наименование'], 'parent_id' => ['label' => 'Родитель'], 'basis' => ['label' => 'База состава'], 'accepted_calculation' => ['label' => 'Принятый расчёт'], 'composition' => ['label' => 'Состав'], 'screening' => ['label' => 'Данные для определения МД']];
    }

    public function changes(string $key, array $field, mixed $old, mixed $new): array
    {
        $text = fn ($value) => $value === null ? null : (is_bool($value) ? ($value ? 'да' : 'нет') : (string) $value);
        if ($key === 'screening') {
            $changes = [];
            foreach (['values' => 'Ответ', 'comments' => 'Комментарий', 'thresholds' => 'Порог'] as $section => $label) {
                foreach (array_unique([...array_keys($old[$section] ?? []), ...array_keys($new[$section] ?? [])]) as $id) {
                    $a = $old[$section][$id] ?? null;
                    $b = $new[$section][$id] ?? null;
                    if (! ValueEquality::same($a, $b)) {
                        $changes[] = ['key' => $key.'.'.$section.'.'.$id, 'label' => $label.' · '.$id, 'from' => is_array($a) ? json_encode($a, JSON_UNESCAPED_UNICODE) : $text($a), 'to' => is_array($b) ? json_encode($b, JSON_UNESCAPED_UNICODE) : $text($b)];
                    }
                }
            }

            return $changes;
        }
        if ($key !== 'composition') {
            return ValueEquality::same($old, $new) ? [] : [['key' => $key, 'label' => $field['label'], 'from' => $text($old), 'to' => $text($new)]];
        }
        $changes = [];
        foreach (array_unique([...array_keys($old ?? []), ...array_keys($new ?? [])]) as $id) {
            $a = $old[$id] ?? [];
            $b = $new[$id] ?? [];
            $label = 'Состав · '.substr($id, 0, 8);
            if ($a === [] || $b === []) {
                $changes[] = ['key' => $id, 'label' => $label, 'from' => $a === [] ? null : 'строка', 'to' => $b === [] ? 'удалена' : 'добавлена'];
            }
            foreach (['substance_id' => 'вещество', 'role' => 'роль', 'name' => 'наименование', 'amount' => 'содержание', 'unit' => 'единица', 'plus' => 'и выше', 'notes' => 'комментарий', 'position' => 'порядок'] as $attribute => $title) {
                if (! ValueEquality::same($a[$attribute] ?? null, $b[$attribute] ?? null)) {
                    $changes[] = ['key' => $id.'|'.$attribute, 'label' => $label.' · '.$title, 'from' => $text($a[$attribute] ?? null), 'to' => $text($b[$attribute] ?? null)];
                }
            }
        }

        return $changes;
    }
}
