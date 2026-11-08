<?php

return [
    'categories' => ['main' => 'Основные технологические потоки', 'alternative' => 'Альтернативные потоки', 'auxiliary' => 'Вспомогательные потоки', 'repair' => 'Ремонтные потоки', 'external' => 'Наружные среды'],
    'media' => ['fluid' => 'Технологическая среда', 'air' => 'Атмосфера', 'soil' => 'Грунт', 'insulation' => 'Теплоизоляция'],
    'fields' => [
        'temperature' => ['label' => 'Температура', 'type' => 'number', 'unit' => '°C', 'min' => -273.15, 'max' => 3000],
        'pressure' => ['label' => 'Избыточное давление', 'type' => 'number', 'unit' => 'МПа', 'min' => -0.101325, 'max' => 1000],
        'ph' => ['label' => 'pH', 'type' => 'number', 'unit' => '—', 'min' => 0, 'max' => 14],
        'density' => ['label' => 'Принятая плотность', 'type' => 'number', 'unit' => 'кг/м³', 'min' => 0, 'max' => 100000],
        'molecular_weight' => ['label' => 'Принятая молекулярная масса', 'type' => 'number', 'unit' => 'г/моль', 'min' => 0, 'max' => 1000000],
        'boiling_point' => ['label' => 'Принятая нормальная температура кипения', 'type' => 'number', 'unit' => '°C', 'min' => -273.15, 'max' => 10000],
        'phase' => ['label' => 'Агрегатное состояние', 'type' => 'choice', 'options' => ['gas', 'liquid', 'two_phase', 'solid']],
        'rep_environment' => ['label' => 'Принятая репрезентативная среда', 'type' => 'text', 'max' => 1000],
        'soil_type' => ['label' => 'Тип грунта', 'type' => 'text', 'max' => 255],
        'insulation_type' => ['label' => 'Тип теплоизоляции', 'type' => 'text', 'max' => 255],
        'insulation_chlorides' => ['label' => 'Хлориды в теплоизоляции', 'type' => 'choice', 'options' => ['yes', 'no', 'unknown']],
        'notes' => ['label' => 'Комментарий', 'type' => 'text', 'max' => 10000],
    ],
    'impurity_units' => ['ppmw', 'ppm_mol', 'mg_l', 'g_m3', 'mass_percent', 'mole_percent', 'other'],
];
