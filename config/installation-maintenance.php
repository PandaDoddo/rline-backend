<?php

return [
    'fields' => [
        'mri' => ['effective_date' => 'Дата МРИ', 'interval_months' => 'МРИ, мес.', 'reason' => 'Основание'],
        'repair' => ['starts_on' => 'Начало ремонта', 'ends_on' => 'Окончание ремонта', 'repair_kind' => 'Вид ремонта', 'reason' => 'Примечание'],
        'stop' => ['starts_at' => 'Начало останова', 'ends_at' => 'Окончание останова', 'reason' => 'Причина'],
    ],
    'repair_kinds' => ['capital' => 'капитальный', 'extended_medium' => 'увеличенный средний', 'medium' => 'средний'],
];
