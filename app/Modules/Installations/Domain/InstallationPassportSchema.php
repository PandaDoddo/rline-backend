<?php

namespace App\Modules\Installations\Domain;

/** Public aliases remain stable; physical facts have explicit typed owners. */
final class InstallationPassportSchema
{
    public const FIELDS = [
        'full' => ['full_name', 'text', 255], 'purpose' => ['purpose', 'text', 5000], 'year' => ['commissioned_year', 'text', 255],
        'capP' => ['planned_capacity', 'number', 999999999999], 'capF' => ['actual_capacity', 'number', 999999999999], 'capU' => ['capacity_unit', 'text', 80],
        'designer' => ['designer', 'text', 255], 'licensor' => ['licensor', 'text', 255], 'opoReg' => ['hazardous_facility_registration', 'text', 255],
        'opoCls' => ['hazardous_facility_class', 'text', 10], 'cat' => ['fire_category', 'text', 10], 'atm' => ['atmospheric_conditions', 'text', 40],
        'area' => ['area_m2', 'number', 999999999999], 'stShift' => ['staff_per_shift', 'integer', 10000000], 'stAll' => ['staff_total', 'integer', 10000000],
        'head' => ['head_name', 'text', 255], 'mech' => ['mechanic_name', 'text', 255],
    ];

    public const OPTIONS = ['opoCls' => ['I', 'II', 'III', 'IV'], 'cat' => ['А', 'Б', 'В1', 'В2', 'В3', 'В4', 'Г', 'Д', 'Ан', 'Бн', 'Вн', 'Гн', 'Дн'], 'atm' => ['тяжелые', 'умеренные', 'мягкие', 'сухие']];

    public static function rules(): array
    {
        $rules = ['passport' => 'sometimes|array:'.implode(',', array_keys(self::FIELDS))];
        foreach (self::FIELDS as $key => [$column, $type, $max]) {
            $rule = match ($type) {
                'number' => 'nullable|numeric|min:0|max:'.$max,
                'integer' => 'nullable|integer|min:0|max:'.$max,
                default => 'nullable|string|max:'.$max,
            };
            if (isset(self::OPTIONS[$key])) {
                $rule .= '|in:'.implode(',', self::OPTIONS[$key]);
            }
            $rules['passport.'.$key] = 'sometimes|'.$rule;
        }
        $rules['passport.capU'] = 'nullable|required_with:passport.capP,passport.capF|string|max:80';

        return $rules;
    }
}
