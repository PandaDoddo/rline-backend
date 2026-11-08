<?php

namespace App\Domain;

class ProcessFlowHistory
{
    public function fields(): array
    {
        return config('process-flows.fields') + ['name' => ['label' => 'Наименование'], 'parent_id' => ['label' => 'Родитель'], 'basis' => ['label' => 'База состава'], 'accepted_calculation' => ['label' => 'Принятый расчёт'], 'composition' => ['label' => 'Состав']];
    }

    public function changes(string $key, array $field, mixed $old, mixed $new): array
    {
        $text = fn ($value) => $value === null ? null : (is_bool($value) ? ($value ? 'да' : 'нет') : (string) $value);
        if ($key !== 'composition') {
            return CatalogValues::same($old, $new) ? [] : [['key' => $key, 'label' => $field['label'], 'from' => $text($old), 'to' => $text($new)]];
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
                if (! CatalogValues::same($a[$attribute] ?? null, $b[$attribute] ?? null)) {
                    $changes[] = ['key' => $id.'|'.$attribute, 'label' => $label.' · '.$title, 'from' => $text($a[$attribute] ?? null), 'to' => $text($b[$attribute] ?? null)];
                }
            }
        }

        return $changes;
    }
}
