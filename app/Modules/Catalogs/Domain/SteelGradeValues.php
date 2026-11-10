<?php

namespace App\Modules\Catalogs\Domain;

class SteelGradeValues
{
    public static function normalize(string $key, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
            if (in_array($value, ['', '—', '---'], true)) {
                return null;
            }
            if ((config('steel-grades.fields.'.$key.'.type')) === 'number') {
                $number = str_replace([' ', ','], ['', '.'], $value);
                if (is_numeric($number)) {
                    return $number + 0;
                }
            }
        }
        if ($key === 'composition' && is_array($value)) {
            return array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }
                foreach (['min', 'max'] as $field) {
                    if (isset($row[$field]) && is_string($row[$field])) {
                        $number = str_replace([' ', ','], ['', '.'], trim($row[$field]));
                        $row[$field] = $number === '' ? null : (is_numeric($number) ? $number + 0 : $row[$field]);
                    }
                }
                if (is_string($row['el'] ?? null)) {
                    $row['el'] = trim($row['el']);
                }

                return $row;
            }, $value);
        }
        if ($key === 'stress' && is_array($value) && is_array($value['rows'] ?? null)) {
            $value['rows'] = array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                } foreach ($row as $key => $cell) {
                    if (is_string($cell) && ! in_array($key, ['note', 'lerp'], true)) {
                        $number = str_replace([' ', ','], ['', '.'], trim($cell));
                        $row[$key] = $number === '' ? null : (is_numeric($number) ? $number + 0 : $cell);
                    }
                }

                return $row;
            }, $value['rows']);
        }

        return $value;
    }
}
