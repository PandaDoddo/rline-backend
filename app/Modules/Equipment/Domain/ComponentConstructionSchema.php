<?php

namespace App\Modules\Equipment\Domain;

final class ComponentConstructionSchema
{
    public const LABELS = [
        'mark' => 'Обозначение',
        'joining_method' => 'Способ присоединения',
        'manufacturing_method' => 'Способ изготовления',
        'manufacturing_standard' => 'НТД изготовления',
        'execution' => 'Исполнение',
        'assembly_kind' => 'Структура компонента',
        'line_number' => 'Номер линии',
        'service_name' => 'Назначение',
        'nominal_pressure_mpa' => 'Номинальное давление PN, МПа',
        'seam_type' => 'Тип трубы',
        'is_primary' => 'Основной компонент',
        'coil_body_kind' => 'Структура тела змеевика',
        'flange_type' => 'Тип фланца',
    ];

    public const OPTIONS = [
        'assembly_kind' => ['mono', 'courses', 'assembly', 'coil', 'spools'],
        'seam_type' => ['seamless', 'welded'],
        'coil_body_kind' => ['mono', 'multi'],
    ];

    public static function rules(): array
    {
        $rules = ['structure.*.construction' => 'sometimes|nullable|array:'.implode(',', array_keys(self::LABELS))];
        foreach (self::LABELS as $field => $label) {
            $rules['structure.*.construction.'.$field] = match ($field) {
                'is_primary' => 'sometimes|nullable|boolean',
                'nominal_pressure_mpa' => 'sometimes|nullable|numeric|min:0|max:10000',
                default => 'sometimes|nullable|string|max:255'.(isset(self::OPTIONS[$field]) ? '|in:'.implode(',', self::OPTIONS[$field]) : ''),
            };
        }

        return $rules;
    }
}
