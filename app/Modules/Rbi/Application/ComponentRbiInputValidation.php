<?php

namespace App\Modules\Rbi\Application;

final class ComponentRbiInputValidation
{
    public static function rules(): array
    {
        return ['component_rbi' => 'sometimes|array|list|max:2000',
            'component_rbi.*' => 'required|array:component_id,weld_efficiency,injection_point,dead_leg,confidence,notes',
            'component_rbi.*.component_id' => 'required|uuid|distinct',
            'component_rbi.*.weld_efficiency' => 'sometimes|nullable|numeric|gt:0|lte:1',
            'component_rbi.*.injection_point' => 'sometimes|nullable|boolean',
            'component_rbi.*.dead_leg' => 'sometimes|nullable|boolean',
            'component_rbi.*.confidence' => 'sometimes|nullable|in:low,med,high',
            'component_rbi.*.notes' => 'sometimes|nullable|string|max:4000|not_regex:/\x00/u'];
    }

    public function normalize(array $rows): array
    {
        foreach ($rows as &$row) {
            if (! is_array($row)) {
                continue;
            }
            if (is_string($row['weld_efficiency'] ?? null)) {
                $value = str_replace(',', '.', trim($row['weld_efficiency']));
                $row['weld_efficiency'] = $value === '' ? null : $value;
            }
            foreach (['injection_point', 'dead_leg'] as $field) {
                if (array_key_exists($field, $row) && in_array($row[$field], ['0', '1', 0, 1], true)) {
                    $row[$field] = (bool) $row[$field];
                }
            }
            if (is_string($row['notes'] ?? null)) {
                $row['notes'] = trim($row['notes']);
                if ($row['notes'] === '') {
                    $row['notes'] = null;
                }
            }
        }
        unset($row);

        return $rows;
    }
}
