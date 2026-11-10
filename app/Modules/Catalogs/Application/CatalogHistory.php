<?php

namespace App\Modules\Catalogs\Application;

use App\Modules\Catalogs\Domain\CatalogValues;
use App\Modules\Shared\Application\AuditEvents;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CatalogHistory
{
    public function page(string $entity, string $id, array $fields, ?callable $valueChanges = null): LengthAwarePaginator
    {
        $page = app(AuditEvents::class)->page($entity, $id);
        $text = fn ($value) => $value === null ? null : (is_bool($value) ? ($value ? 'да' : 'нет') : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (string) $value));
        $page->getCollection()->transform(function ($row) use ($fields, $valueChanges, $text) {
            $before = $row->before ?? [];
            $after = $row->after;
            $old = array_replace($before['base_values'] ?? [], $before['overrides'] ?? []);
            $new = array_replace($after['base_values'] ?? [], $after['overrides'] ?? []);
            $changes = [];
            foreach (array_unique([...array_keys($before['checks'] ?? []), ...array_keys($after['checks'] ?? [])]) as $target) {
                if (str_starts_with($target, 'cell:') && isset($before['checks'][$target]) !== isset($after['checks'][$target])) {
                    $changes[] = ['key' => $target.'|check', 'label' => 'Проверка ячейки · '.$target, 'from' => isset($before['checks'][$target]) ? 'проверено' : 'не проверено', 'to' => isset($after['checks'][$target]) ? 'проверено' : 'не проверено'];
                }
            }
            foreach ($fields as $key => $field) {
                if ($valueChanges) {
                    array_push($changes, ...$valueChanges($key, $field, $old[$key] ?? null, $new[$key] ?? null));
                } elseif (! CatalogValues::same($old[$key] ?? null, $new[$key] ?? null)) {
                    $changes[] = ['key' => $key, 'label' => $field['label'], 'from' => $text($old[$key] ?? null), 'to' => $text($new[$key] ?? null)];
                }
                $oldCheck = isset($before['checks'][$key]);
                $newCheck = isset($after['checks'][$key]);
                if ($oldCheck !== $newCheck) {
                    $changes[] = ['key' => $key.'|check', 'label' => 'Проверка · '.$field['label'], 'from' => $oldCheck ? 'проверено' : 'не проверено', 'to' => $newCheck ? 'проверено' : 'не проверено'];
                }
                foreach (['citation' => 'Источник', 'conditions' => 'Условия'] as $part => $label) {
                    $oldSource = $before['sources'][$key][$part] ?? null;
                    $newSource = $after['sources'][$key][$part] ?? null;
                    if ($oldSource !== $newSource) {
                        $changes[] = ['key' => $key.'|'.$part, 'label' => $label.' · '.$field['label'], 'from' => $oldSource, 'to' => $newSource];
                    }
                }
            }
            if (($before['status'] ?? null) !== ($after['status'] ?? null)) {
                $changes[] = ['key' => 'status', 'label' => 'Состояние', 'from' => $before['status'] ?? null, 'to' => $after['status'] ?? null];
            }
            $row->changes = $changes;

            return $row;
        });

        return $page;
    }
}
