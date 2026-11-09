<?php

$config = [
    'profiles' => json_decode(file_get_contents(resource_path('catalogs/equipment-profiles.json')), true, flags: JSON_THROW_ON_ERROR),
    'passport' => [
        'name' => ['label' => 'Наименование', 'section' => 'Идентификатор', 'rules' => 'nullable|required_without:passport_qualifiers.name.state|string|min:2|max:255'],
        'additional_name' => ['label' => 'Дополнительное наименование', 'section' => 'Идентификатор', 'rules' => 'nullable|string|max:255'],
        'equipment_class' => ['label' => 'Класс оборудования', 'section' => 'Идентификатор', 'rules' => 'nullable|string|max:100'],
        'tag' => ['label' => 'Техн. позиция', 'section' => 'Идентификатор', 'rules' => 'nullable|string|max:100'],
        'serial_number' => ['label' => 'Заводской номер', 'section' => 'Идентификатор', 'rules' => 'nullable|string|max:100'],
        'registration_number' => ['label' => 'Регистрационный номер', 'section' => 'Идентификатор', 'rules' => 'nullable|string|max:100'],
        'manufacturer' => ['label' => 'Изготовитель', 'section' => 'Идентификатор', 'rules' => 'nullable|string|max:255'],
        'manufactured_on' => ['label' => 'Дата изготовления', 'section' => 'Идентификатор', 'type' => 'date', 'rules' => 'nullable|date_format:Y-m-d'],
        'mounted_on' => ['label' => 'Дата монтажа', 'section' => 'Идентификатор', 'type' => 'date', 'rules' => 'nullable|date_format:Y-m-d'],
        'commissioned_on' => ['label' => 'Дата ввода в эксплуатацию', 'section' => 'Идентификатор', 'type' => 'date', 'rules' => 'nullable|date_format:Y-m-d'],
        'commissioned_precision' => ['label' => 'Точность даты ввода', 'section' => 'Идентификатор', 'options' => ['day', 'year'], 'rules' => 'nullable|in:day,year'],
        'design_life_years' => ['label' => 'Срок службы изготовителя, лет', 'section' => 'Идентификатор', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:200'],
        'ntd' => ['label' => 'Основной нормативный документ', 'section' => 'Классификатор', 'rules' => 'nullable|string|max:255'],
        'registration_authority' => ['label' => 'Место регистрации', 'section' => 'Классификатор', 'options' => ['РТН', 'ОТН'], 'rules' => 'nullable|in:РТН,ОТН'],
        'vessel_group' => ['label' => 'Группа сосуда по НТД', 'section' => 'Классификатор', 'options' => ['1', '2'], 'groups' => ['ves'], 'rules' => 'nullable|in:1,2'],
        'productivity_reduction_percent' => ['label' => 'Снижение производительности, %', 'section' => 'Классификатор', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:100'],
        'material' => ['label' => 'Основной материал', 'section' => 'Конструкция', 'rules' => 'nullable|string|max:255'],
        'medium' => ['label' => 'Рабочая среда', 'section' => 'Рабочие параметры', 'rules' => 'nullable|string|max:255'],
        'pressure_test_mpa' => ['label' => 'Пробное давление, МПа', 'section' => 'Рабочие параметры', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:10000'],
        'pressure_design_mpa' => ['label' => 'Расчётное давление, МПа', 'section' => 'Рабочие параметры', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:10000'],
        'pressure_allowed_mpa' => ['label' => 'Разрешённое давление, МПа', 'section' => 'Рабочие параметры', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:10000'],
        'pressure_operating_mpa' => ['label' => 'Рабочее давление, МПа', 'section' => 'Рабочие параметры', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:10000'],
        'temperature_design_c' => ['label' => 'Расчётная температура, °C', 'section' => 'Рабочие параметры', 'type' => 'number', 'rules' => 'nullable|numeric|min:-273.15|max:3000'],
        'temperature_max_c' => ['label' => 'Рабочая температура max, °C', 'section' => 'Рабочие параметры', 'type' => 'number', 'rules' => 'nullable|numeric|min:-273.15|max:3000'],
        'temperature_min_c' => ['label' => 'Рабочая температура min, °C', 'section' => 'Рабочие параметры', 'type' => 'number', 'rules' => 'nullable|numeric|min:-273.15|max:3000'],
        'volume_m3' => ['label' => 'Объём, м³', 'section' => 'Конструкция', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:1000000000'],
        'diameter_m' => ['label' => 'Диаметр, м', 'section' => 'Конструкция', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:100000'],
        'height_m' => ['label' => 'Высота, м', 'section' => 'Конструкция', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:100000'],
        'length_m' => ['label' => 'Длина, м', 'section' => 'Конструкция', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:1000000000'],
        'nominal_thickness_mm' => ['label' => 'Номинальная толщина, мм', 'section' => 'Конструкция', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:100000'],
        'cladding_thickness_mm' => ['label' => 'Толщина плакировки, мм', 'section' => 'Защитные покрытия', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:100000'],
        'coating_type' => ['label' => 'Антикоррозионное покрытие', 'section' => 'Защитные покрытия', 'rules' => 'nullable|string|max:255'],
        'insulation_type' => ['label' => 'Теплоизоляция', 'section' => 'Защитные покрытия', 'rules' => 'nullable|string|max:255'],
        'location_notes' => ['label' => 'Титул / примечание к размещению', 'section' => 'Местоположение', 'rules' => 'nullable|string|max:2000'],
        'design_corrosion_mm_year' => ['label' => 'Проектная скорость коррозии, мм/год', 'section' => 'Скорость коррозии', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:1000'],
        'active_otn_years' => ['label' => 'Действующая периодичность ТО ОТН, лет', 'section' => 'Диагностика', 'type' => 'number', 'groups' => ['ves'], 'rules' => 'nullable|integer|min:1|max:100'],
        'active_rtn_years' => ['label' => 'Действующая периодичность ТО РТН, лет', 'section' => 'Диагностика', 'type' => 'number', 'groups' => ['ves'], 'rules' => 'nullable|integer|min:1|max:100'],
        'active_hydro_years' => ['label' => 'Действующая периодичность ГИ, лет', 'section' => 'Диагностика', 'type' => 'number', 'groups' => ['ves'], 'rules' => 'nullable|integer|min:1|max:100'],
    ],
];

$extension = require __DIR__.'/equipment-passport.php';
$config['passport'] = array_merge($config['passport'], $extension['fields']);
foreach ($extension['aliases'] as $key => $paths) {
    $config['passport'][$key]['legacy_keys'] = array_values(array_unique([...($config['passport'][$key]['legacy_keys'] ?? []), ...$paths]));
}

return $config;
