<?php

namespace App\Domain;

class MaintenanceHistory
{
    public function changes(string $key, array $field, mixed $old, mixed $new): array
    {
        if ($key === 'no_stops') {
            return CatalogValues::same($old, $new) ? [] : [['key' => $key, 'label' => $field['label'], 'from' => $old === null ? null : ($old ? 'да' : 'нет'), 'to' => $new === null ? null : ($new ? 'да' : 'нет')]];
        }
        $changes = [];
        foreach (array_unique([...array_keys($old ?? []), ...array_keys($new ?? [])]) as $id) {
            $a = $old[$id] ?? null;
            $b = $new[$id] ?? null;
            $kind = ($b ?? $a)['kind'];
            $label = ['mri' => 'МРИ', 'repair' => 'Ремонт', 'stop' => 'Останов'][$kind].' · '.substr($id, 0, 8);
            if ($a === null || $b === null) {
                $changes[] = ['key' => $id, 'label' => $label, 'from' => $a === null ? null : 'запись', 'to' => $b === null ? 'удалена' : 'добавлена'];
            }
            foreach (config('installation-maintenance.fields.'.$kind) as $name => $title) {
                $from = $a['values'][$name] ?? null;
                $to = $b['values'][$name] ?? null;
                if (! CatalogValues::same($from, $to)) {
                    $changes[] = ['key' => $id.'|'.$name, 'label' => $label.' · '.$title, 'from' => $from === null ? null : (string) $from, 'to' => $to === null ? null : (string) $to];
                }
            }
            if (isset($a['check']) !== isset($b['check'])) {
                $changes[] = ['key' => $id.'|check', 'label' => $label.' · проверка', 'from' => isset($a['check']) ? 'проверено' : 'не проверено', 'to' => isset($b['check']) ? 'проверено' : 'не проверено'];
            }
        }

        return $changes;
    }
}
