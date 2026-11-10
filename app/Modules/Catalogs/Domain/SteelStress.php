<?php

namespace App\Modules\Catalogs\Domain;

/** A projection only: computed values are never persisted as raw anchors. */
final class SteelStress
{
    public static function rows(?array $stress): array
    {
        $rows = $stress['rows'] ?? [];
        $series = [];
        foreach ($stress['columns'] ?? [] as $column) {
            $id = $column['id'];
            $anchors = array_values(array_filter($rows, fn ($point) => is_numeric($point[$id] ?? null)));
            usort($anchors, fn ($a, $b) => $a['t'] <=> $b['t']);
            $series[$id] = $anchors;
        }
        foreach ($rows as &$row) {
            $row['interpolated'] = [];
            foreach ($stress['columns'] ?? [] as $column) {
                $id = $column['id'];
                if (is_numeric($row[$id] ?? null) || ($row['lerp'] ?? false) !== true) {
                    continue;
                }
                $anchors = $series[$id];
                $lo = 0;
                $hi = count($anchors);
                while ($lo < $hi) {
                    $mid = intdiv($lo + $hi, 2);
                    if ($anchors[$mid]['t'] < $row['t']) {
                        $lo = $mid + 1;
                    } else {
                        $hi = $mid;
                    }
                }
                $left = $anchors[$lo - 1] ?? null;
                $right = $anchors[$lo] ?? null;
                if ($left !== null && $right !== null) {
                    $row[$id] = $left[$id] + ($right[$id] - $left[$id]) * ($row['t'] - $left['t']) / ($right['t'] - $left['t']);
                    $row['interpolated'][$id] = ['from' => $left['t'], 'to' => $right['t']];
                }
            }
            $row['interpolated'] = (object) $row['interpolated'];
        }
        unset($row);

        return $rows;
    }
}
