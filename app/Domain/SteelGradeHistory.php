<?php

namespace App\Domain;

class SteelGradeHistory
{
    public function changes(string $key, array $field, mixed $before, mixed $after): array
    {
        if (CatalogValues::same($before, $after)) {
            return [];
        }
        $text = fn ($value) => $value === null ? null : (is_bool($value) ? ($value ? 'да' : 'нет') : (string) $value);
        if (! in_array($key, ['composition', 'stress'], true)) {
            return [['key' => $key, 'label' => $field['label'], 'from' => $text($before), 'to' => $text($after)]];
        }
        $flatten = function ($value) use ($key) {
            $result = [];
            if ($key === 'composition') {
                foreach ($value ?? [] as $row) {
                    foreach (['min', 'max'] as $column) {
                        $result[$row['el'].' · '.$column] = $row[$column] ?? null;
                    }
                }
            } else {
                foreach ($value['columns'] ?? [] as $column) {
                    $result['Источник · '.$column['id']] = $column['label'];
                }
                foreach ($value['rows'] ?? [] as $row) {
                    foreach ($row as $column => $cell) {
                        if ($column !== 't') {
                            $result[(string) $row['t'].' °C · '.$column] = $cell;
                        }
                    }
                }
            }

            return $result;
        };
        $old = $flatten($before);
        $new = $flatten($after);
        $changes = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $cell) {
            // Added/removed empty rows remain visible in history too.
            $from = array_key_exists($cell, $old) ? $text($old[$cell]) : '(не было)';
            $to = array_key_exists($cell, $new) ? $text($new[$cell]) : '(удалено)';
            if ($from !== $to) {
                $changes[] = ['key' => $key.'|'.$cell, 'label' => $field['label'].' · '.$cell, 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }
}
