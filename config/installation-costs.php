<?php

$fields = [];
foreach (['envcost' => ['Очистка окружающей среды', 'руб/м³'], 'injcost' => ['Травмирование персонала', 'руб'], 'popdens' => ['Плотность персонала', 'чел/м²'], 'prodcost' => ['Производственные потери при простое', 'руб/сут'], 'equipcost' => ['Стоимость оборудования на единицу площади', 'руб/м²'], 'costfactor' => ['Множитель стоимости ремонта', '—'], 'outagemult' => ['Множитель времени простоя', '—']] as $key => [$label, $unit]) {
    $fields[$key] = ['label' => $label, 'unit' => $unit, 'type' => 'number', 'min' => 0, 'max' => 1000000000000000];
}
foreach (['detsys' => ['Система обнаружения утечек', 'det', 'API 581, табл. 4.5'], 'isosys' => ['Система изоляции', 'iso', 'API 581, табл. 4.5'], 'mitsys' => ['Система смягчения воздействия', 'mit', 'API 581, табл. 4.10']] as $key => [$label, $options, $source]) {
    $fields[$key] = ['label' => $label, 'unit' => '—', 'type' => 'select', 'options' => $options, 'source' => $source];
}

return ['fields' => $fields];
