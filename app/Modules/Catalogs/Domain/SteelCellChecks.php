<?php

namespace App\Modules\Catalogs\Domain;

use Illuminate\Validation\ValidationException;

/** Stable cell identities do not depend on row/column ordering. */
final class SteelCellChecks
{
    public static function values(array $values): array
    {
        $cells = [];
        foreach ($values['composition'] ?? [] as $row) {
            foreach (['min', 'max'] as $bound) {
                $cells['cell:composition:'.$row['el'].':'.$bound] = $row[$bound] ?? null;
            }
        }
        $stress = $values['stress'] ?? [];
        foreach ($stress['columns'] ?? [] as $column) {
            $cells['cell:source:'.$column['id']] = $column['label'];
        }
        foreach ($stress['rows'] ?? [] as $row) {
            $prefix = 'cell:stress:'.(string) (float) $row['t'];
            $cells[$prefix.':t'] = $row['t'];
            foreach ($stress['columns'] ?? [] as $column) {
                // An interpolation change or source rename invalidates an existing stamp.
                $cells[$prefix.':'.$column['id']] = isset($row[$column['id']])
                    ? [$row[$column['id']], $row['lerp'] ?? false, $column['label']] : null;
            }
        }

        return $cells;
    }

    public static function reconcile(array $before, array $after, array $checks, array $requested, int $actor): array
    {
        $old = self::values($before);
        $new = self::values($after);
        foreach (array_keys($checks) as $key) {
            if (str_starts_with($key, 'cell:') && (! array_key_exists($key, $new) || ! CatalogValues::same($old[$key] ?? null, $new[$key]))) {
                unset($checks[$key]);
            }
        }
        foreach ($requested as $index => $item) {
            $key = $item['target'];
            if (! array_key_exists($key, $new)) {
                throw ValidationException::withMessages(['cell_verification.'.$index.'.target' => 'Ячейка не существует.']);
            }
            if (! $item['checked']) {
                unset($checks[$key]);
            } elseif ($new[$key] === null || $new[$key] === '') {
                throw ValidationException::withMessages(['cell_verification.'.$index.'.target' => 'Нельзя проверить пустую или расчётную ячейку.']);
            } else {
                $checks[$key] ??= ['by' => $actor, 'at' => now()->toIso8601String()];
            }
        }

        return $checks;
    }
}
