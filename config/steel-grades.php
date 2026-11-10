<?php

$fields = [];
foreach (['name' => 'Марка', 'analog' => 'Российский аналог', 'aisi' => 'AISI / ASTM', 'en' => 'EN', 'kind' => 'Вид материала', 'subtype' => 'Подтип', 'refine' => 'Уточнение', 'std' => 'Стандарт', 'rm_src' => 'Источник временного сопротивления', 're_src' => 'Источник предела текучести', 'tSrc' => 'Источник температурных ограничений'] as $key => $label) {
    $fields[$key] = ['label' => $label, 'section' => 'Общие сведения', 'type' => 'text', 'max' => $key === 'name' ? 255 : 4000];
}
foreach (['rm_nom' => 'Временное сопротивление · номинальное', 'rm_min' => 'Временное сопротивление · минимум', 'rm_max' => 'Временное сопротивление · максимум', 're_nom' => 'Предел текучести · номинальный', 're_min' => 'Предел текучести · минимум', 're_max' => 'Предел текучести · максимум', 'hbBase_min' => 'HB основной металл · минимум', 'hbBase_max' => 'HB основной металл · максимум', 'hbWeld_min' => 'HB сварной шов · минимум', 'hbWeld_max' => 'HB сварной шов · максимум'] as $key => $label) {
    $fields[$key] = ['label' => $label, 'section' => 'Механические свойства', 'type' => 'number', 'unit' => str_starts_with($key, 'hb') ? 'HB' : 'МПа', 'min' => 0, 'max' => 100000];
}
foreach (['matMin' => 'Минимальная температура применения', 'matMax' => 'Максимальная температура применения', 'maxApp' => 'Предельная температура таблицы напряжений', 'rbi_dfatt' => 'DFATT'] as $key => $label) {
    $fields[$key] = ['label' => $label, 'section' => $key === 'rbi_dfatt' ? 'Критерии RBI' : 'Общие сведения', 'type' => 'number', 'unit' => '°C', 'min' => -273.15, 'max' => 3000];
}
foreach (['hbBase_accepted', 'hbWeld_accepted'] as $key) {
    $fields[$key] = ['label' => $key === 'hbBase_accepted' ? 'HB основной металл · принятое' : 'HB сварной шов · принятое', 'section' => 'Механические свойства', 'type' => 'number', 'unit' => 'HB', 'min' => 0, 'max' => 100000];
}
foreach (['matMin_min', 'matMin_max', 'matMax_min', 'matMax_max'] as $key) {
    $fields[$key] = ['label' => $key, 'section' => 'Температурные диапазоны', 'type' => 'number', 'unit' => '°C', 'min' => -273.15, 'max' => 3000];
}
foreach (['rbi_group' => 'Группа RBI', 'rbi_tref' => 'Кривая исключения ASME', 'rbi_crCO2' => 'Критерий Cr · CO₂', 'rbi_cr885' => 'Критерий Cr · 885 °F', 'rbi_sHIC' => 'Критерий S · HIC', 'rbi_austGroup' => 'Группа аустенитной стали', 'rbi_sigmaContent' => 'Содержание сигма-фазы', 'rbi_hardness' => 'Категория твёрдости', 'rbi_moAust' => 'Критерий Mo', 'sigma_suscept' => 'Восприимчивость к сигма-охрупчиванию', 'sigma_group' => 'Группа сигма-фазы', 'sigma_range' => 'Диапазон сигма-фазы', 'sigma_note' => 'Примечание по сигма-фазе'] as $key => $label) {
    $fields[$key] = ['label' => $label, 'section' => 'Критерии RBI', 'type' => 'text', 'max' => 4000];
}
foreach (['rbi_jfac' => 'J-фактор', 'rbi_xbar' => 'X̄-фактор'] as $key => $label) {
    $fields[$key] = ['label' => $label, 'section' => 'Критерии RBI', 'type' => 'number', 'min' => 0, 'max' => 1000000];
}
$fields['rbi_matcost'] = ['label' => 'Историческое matcost (не применяется)', 'section' => 'История источника', 'type' => 'number', 'readonly' => true, 'min' => 0, 'max' => 1000000];
$fields['accepted_cost'] = ['label' => 'Принятый matcost (справочник)', 'section' => 'Стоимость материала', 'type' => 'number', 'readonly' => true, 'min' => 0, 'max' => 1000000];
$fields['cost_reason'] = ['label' => 'Обоснование matcost (справочник)', 'section' => 'Стоимость материала', 'type' => 'text', 'readonly' => true, 'max' => 4000];
$fields['composition'] = ['label' => 'Химический состав', 'section' => 'Состав', 'type' => 'composition', 'unit' => '% масс.'];
$fields['stress'] = ['label' => 'Допускаемые напряжения', 'section' => 'Напряжения', 'type' => 'stress', 'unit' => 'МПа'];

return ['fields' => $fields, 'missing_values' => ['не применимо', 'нет данных', 'не установлено']];
