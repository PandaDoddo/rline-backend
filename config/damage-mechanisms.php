<?php

$groups = ['cs' => 'Углеродистые и низколегированные стали', 'crmo' => 'Cr-Mo стали', 'ss' => 'Нержавеющие аустенитные стали', 'fss' => 'Нержавеющие ферритные стали', 'mss' => 'Нержавеющие мартенситные стали', 'dss' => 'Дуплексные стали', 'cu' => 'Медные сплавы', 'ni' => 'Никелевые сплавы', 'ti' => 'Титан', 'ci' => 'Чугун', 'nm' => 'Неметаллические материалы'];
$sections = ['d' => ['Описание повреждения', 'Description of Damage'], 'mat' => ['Подверженные материалы', 'Affected Materials'], 'crit' => ['Критические факторы', 'Critical Factors'], 'unit' => ['Оборудование и установки', 'Affected Units or Equipment'], 'look' => ['Внешний вид и морфология повреждения', 'Appearance or Morphology of Damage'], 'prev' => ['Предупреждение и снижение', 'Prevention/Mitigation'], 'insp' => ['Контроль и мониторинг', 'Inspection and Monitoring']];
$fields = [
    'name' => ['label' => 'Наименование', 'type' => 'text', 'max' => 255],
    'english_name' => ['label' => 'Английское наименование', 'type' => 'text', 'max' => 500],
    'designation' => ['label' => 'Краткое обозначение', 'type' => 'text', 'max' => 80],
    'paragraph' => ['label' => 'Пункт API 571', 'type' => 'text', 'readonly' => true],
    'page' => ['label' => 'Страница исходного указателя', 'type' => 'integer', 'readonly' => true],
    'kind' => ['label' => 'Вид повреждения', 'type' => 'choice', 'options' => ['коррозия', 'растрескивание', 'усталость', 'охрупчивание', 'механ./металлург.']],
    'df_mapping' => ['label' => 'Фактор повреждения API 581 (справочная связь)', 'type' => 'text', 'max' => 255],
    'material_groups' => ['label' => 'Подверженные группы материалов', 'type' => 'groups'],
    'added_material_groups' => ['label' => 'Дополнения исходного каталога', 'type' => 'groups', 'readonly' => true],
    'related_ids' => ['label' => 'Связанные механизмы', 'type' => 'relations', 'readonly' => true],
    'corrosive_components' => ['label' => 'Коррозионно-активные компоненты', 'type' => 'corrosive-components', 'readonly' => true],
];
foreach ($sections as $key => [$ru, $en]) {
    $fields['ru_'.$key] = ['label' => $ru, 'type' => 'text', 'section' => $key, 'language' => 'ru', 'max' => 30000];
    $fields['en_'.$key] = ['label' => $en.' · summary', 'type' => 'text', 'section' => $key, 'language' => 'en', 'max' => 30000];
    $fields['original_'.$key] = ['label' => $en.' · текст пользователя', 'type' => 'text', 'section' => $key, 'language' => 'original', 'max' => 100000];
}

return ['fields' => $fields, 'groups' => $groups, 'sections' => $sections];
