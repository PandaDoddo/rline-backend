<?php

$fields = [
    'name' => ['label' => 'Наименование', 'section' => 'Описание', 'type' => 'text', 'required' => true, 'max' => 255],
    'formula' => ['label' => 'Формула', 'section' => 'Описание', 'type' => 'text', 'max' => 255],
    'formulaAlt' => ['label' => 'Альтернативная формула', 'section' => 'Описание', 'type' => 'text', 'max' => 255],
    'cas' => ['label' => 'CAS', 'section' => 'Описание', 'type' => 'text', 'max' => 255],
    'icsc' => ['label' => 'Номер ICSC', 'section' => 'Описание', 'type' => 'text', 'max' => 255],
    'intlName' => ['label' => 'Международное наименование', 'section' => 'Описание', 'type' => 'text', 'max' => 1000],
    'intlSyn' => ['label' => 'Синонимы', 'section' => 'Описание', 'type' => 'text', 'max' => 4000],
    'note' => ['label' => 'Описание', 'section' => 'Описание', 'type' => 'text', 'max' => 10000],
    'comment' => ['label' => 'Комментарий', 'section' => 'Описание', 'type' => 'text', 'max' => 10000],
    'phaseNu' => ['label' => 'Фазовое состояние при нормальных условиях', 'section' => 'Свойства', 'type' => 'choice', 'options' => ['газ', 'жидкость', 'твёрдое']],
    'grpRF' => ['label' => 'Группа вещества РФ', 'section' => 'Классификация', 'type' => 'choice', 'options' => ['А', 'А(а)', 'А(б)', 'А(в)', 'Б', 'Б(а)', 'Б(б)', 'Б(в)', 'В', 'В(а)', 'В(б)', 'В(в)', 'В(г)']],
    'hazClass' => ['label' => 'Класс опасности ГОСТ 12.1.007-76', 'section' => 'Классификация', 'type' => 'choice', 'options' => ['1', '2', '3', '4']],
    'hazSanpin' => ['label' => 'Классификация СанПиН', 'section' => 'Классификация', 'type' => 'text', 'max' => 2000],
    'api581cls' => ['label' => 'Классификация API 581', 'section' => 'API 581', 'type' => 'text', 'max' => 1000],
    'repEnv' => ['label' => 'Репрезентативная среда', 'section' => 'API 581', 'type' => 'text', 'max' => 1000],
    'repMethod' => ['label' => 'Основание репрезентативной среды', 'section' => 'API 581', 'type' => 'text', 'max' => 1000],
    'nfpaS' => ['label' => 'Особые риски NFPA / источника', 'section' => 'NFPA', 'type' => 'choice', 'options' => ['-', 'ACID', 'W', 'OX', 'SA']],
];
foreach (['mw' => ['Молярная масса', 'г/моль', 0.000001], 'nbp' => ['Температура кипения', '°C', -273.15], 'ait' => ['Температура самовоспламенения', '°C', -273.15], 'dens' => ['Плотность жидкости', 'кг/м³', 0.000001], 'idlh' => ['IDLH', 'ppm', 0], 'pdk' => ['ПДК воздуха рабочей зоны', 'мг/м³', 0]] as $key => [$label, $unit, $min]) {
    $fields[$key] = ['label' => $label, 'section' => 'Свойства', 'type' => 'number', 'unit' => $unit, 'min' => $min, 'max' => 1000000000];
    foreach (['icsc' => 'ICSC', 'cameo' => 'CAMEO', 'kor' => 'Корольченко', 'nfpa' => 'NFPA 325'] as $source => $sourceLabel) {
        $fields[$key.'_'.$source] = ['label' => $label.' · '.$sourceLabel, 'section' => 'Значения источников', 'type' => 'number', 'unit' => $unit, 'min' => $min, 'max' => 1000000000];
    }
}
foreach (['flammable' => 'Горючий', 'toxic' => 'Токсичный', 'nfnt' => 'NFNT', 'inert' => 'Инертный'] as $key => $label) {
    $fields[$key] = ['label' => $label, 'section' => 'Критерии API 581', 'type' => 'boolean'];
}
foreach (['nfpaH' => 'NFPA · здоровье', 'nfpaF' => 'NFPA · горючесть', 'nfpaI' => 'NFPA · нестабильность'] as $key => $label) {
    $fields[$key] = ['label' => $label, 'section' => 'NFPA', 'type' => 'integer', 'min' => 0, 'max' => 4];
}
foreach (['phaseNu_icsc' => 'Фаза · ICSC', 'phaseNu_cameo' => 'Фаза · CAMEO', 'phaseNu_kor' => 'Фаза · Корольченко'] as $key => $label) {
    $fields[$key] = ['label' => $label, 'section' => 'Значения источников', 'type' => 'text', 'max' => 255];
}
foreach (['mwNote' => 'Молярная масса', 'nbpNote' => 'Кипение', 'aitNote' => 'Самовоспламенение', 'densNote' => 'Плотность', 'idlhNote' => 'IDLH', 'pdkNote' => 'ПДК', 'intlNameNote' => 'Международное наименование', 'phaseNuNote' => 'Фаза', 'hazSanpinNote' => 'СанПиН', 'hazClassNote' => 'Класс опасности'] as $key => $label) {
    $fields[$key] = ['label' => 'Примечание · '.$label, 'section' => 'Источники и условия', 'type' => 'text', 'max' => 10000];
}
foreach (['ref' => 'Общий источник', 'refIcsc' => 'Источник ICSC', 'refCameo' => 'Источник CAMEO', 'refNfpa' => 'Источник NFPA', 'refKor' => 'Источник Корольченко', 'refNiosh' => 'Источник NIOSH'] as $key => $label) {
    $fields[$key] = ['label' => $label, 'section' => 'Источники и условия', 'type' => 'text', 'max' => 4000];
}
$fields['mix'] = ['label' => 'Справочный состав смеси', 'section' => 'Состав', 'type' => 'mix', 'readonly' => true];
$fields['idlhUnit'] = ['label' => 'Единицы IDLH', 'section' => 'Свойства', 'type' => 'choice', 'options' => ['ppm', 'mg/m3']];
$fields['idlh']['qualifiers'] = ['простой асфиксиант'];

return ['fields' => $fields, 'missing_values' => ['не установлено', 'не определено', 'не применимо']];
