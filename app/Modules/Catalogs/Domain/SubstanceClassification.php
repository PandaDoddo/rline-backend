<?php

namespace App\Modules\Catalogs\Domain;

class SubstanceClassification
{
    public function describe(array $values): array
    {
        $descriptions = ['А' => 'Токсичные; уточнить подгруппу', 'А(а)' => 'Токсичные I–II класса опасности', 'А(б)' => 'Токсичные III класса опасности', 'А(в)' => 'Фреоны',
            'Б' => 'Взрыво- и пожароопасные', 'Б(а)' => 'Горючие газы', 'Б(б)' => 'Легковоспламеняющиеся жидкости', 'Б(в)' => 'Горючие жидкости',
            'В' => 'Трудно- и негорючие', 'В(а)' => 'Трудно- и негорючие вещества', 'В(б)' => 'Пар перегретый', 'В(в)' => 'Пар насыщенный', 'В(г)' => 'Горячая вода свыше 115 °C'];
        $group = $values['grpRF'] ?? null;
        $class = $values['hazClass'] ?? null;
        $warnings = [];
        if ($group === 'А') {
            $warnings[] = 'Уточните категорию токсичного вещества.';
        }
        if ($class !== null && $group !== null && (($class === '1' || $class === '2') && $group !== 'А(а)' || $class === '3' && $group === 'А(а)' || $class === '4' && str_starts_with($group, 'А'))) {
            $warnings[] = 'Класс опасности и группа РФ требуют уточнения; явный перечень РД имеет приоритет.';
        }
        $pdk = $values['pdk'] ?? null;
        if (is_int($pdk) || is_float($pdk)) {
            $expected = $pdk < 0.1 ? '1' : ($pdk <= 1 ? '2' : ($pdk >= 1.1 && $pdk <= 10 ? '3' : ($pdk > 10 ? '4' : null)));
            if ($expected === null) {
                $warnings[] = 'Для ПДК между 1,0 и 1,1 мг/м³ требуется уточнить класс по источнику.';
            } elseif ($class !== null && $class !== $expected) {
                $warnings[] = 'Класс опасности не совпадает с указанным диапазоном ПДК; уточните источник.';
            }
        }

        return ['group_description' => $group ? ($descriptions[$group] ?? null) : null, 'warnings' => $warnings];
    }
}
