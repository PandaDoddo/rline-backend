<?php

namespace App\Modules\Equipment\Domain;

final class ComponentDetailSchema
{
    public const BLOCKS = [
        'head_geometry' => [
            'shape' => ['Форма днища', 'text', null],
            'flanging' => ['Отбортовка', 'text', null],
            'height_mm' => ['Высота днища, мм', 'positive', null],
            'outer_diameter_mm' => ['Наружный диаметр днища, мм', 'positive', null],
        ],
        'fitting_geometry' => [
            'bend_radius_designation' => ['Радиус гиба (обозначение)', 'text', ['elbow', 'return_bend']],
            'bend_angle_deg' => ['Угол гиба, °', 'angle', ['elbow', 'return_bend']],
            'large_diameter_mm' => ['Диаметр большого конца, мм', 'positive', ['reducer']],
            'large_thickness_mm' => ['Толщина большого конца, мм', 'positive', ['reducer']],
            'small_diameter_mm' => ['Диаметр малого конца, мм', 'positive', ['reducer']],
            'small_thickness_mm' => ['Толщина малого конца, мм', 'positive', ['reducer']],
            'reducer_form' => ['Геометрия перехода', 'text', ['reducer']],
            'main_diameter_mm' => ['Диаметр магистрали, мм', 'positive', ['tee']],
            'main_thickness_mm' => ['Толщина магистрали, мм', 'positive', ['tee']],
            'branch_diameter_mm' => ['Диаметр ответвления, мм', 'positive', ['tee']],
            'branch_thickness_mm' => ['Толщина ответвления, мм', 'positive', ['tee']],
            'collar_present' => ['Наличие воротника', 'boolean', ['branch_pipe']],
            'collar_diameter_mm' => ['Диаметр воротника, мм', 'positive', ['branch_pipe']],
            'collar_thickness_mm' => ['Толщина воротника, мм', 'positive', ['branch_pipe']],
            'collar_material' => ['Материал воротника', 'text', ['branch_pipe']],
            'collar_hole_present' => ['Наличие отверстия воротника', 'boolean', ['branch_pipe']],
            'collar_hole_thread' => ['Резьба отверстия воротника', 'text', ['branch_pipe']],
            'collar_seam_type' => ['Изготовление воротника', 'text', ['branch_pipe']],
        ],
        'end_connection' => [
            'far_end_kind' => ['Свободный конец', 'text', null],
            'thread_kind' => ['Вид резьбы', 'text', null],
            'thread_size' => ['Размер резьбы', 'text', null],
            'instrument_connection' => ['Подключение КИП', 'boolean', null],
            'instrument_kind' => ['Прибор КИП', 'text', null],
        ],
        'boss_geometry' => [
            'design_type' => ['Тип бобышки', 'text', null],
            'height_mm' => ['Высота бобышки, мм', 'positive', null],
        ],
        'valve_details' => [
            'valve_class' => ['Класс арматуры', 'text', null],
            'valve_kind' => ['Вид арматуры', 'text', null],
            'drive_kind' => ['Привод арматуры', 'text', null],
        ],
        'tube_geometry' => [
            'tube_form' => ['Форма теплообменных трубок', 'text', null],
            'tube_count' => ['Количество теплообменных трубок', 'integer', null],
        ],
        'coil_geometry' => [
            'coil_form' => ['Форма тела змеевика', 'text', null],
        ],
        'lens_geometry' => [
            'outer_diameter_mm' => ['Наружный диаметр линзы, мм', 'positive', null],
        ],
        'expansion_joint' => [
            'joint_kind' => ['Тип компенсатора', 'text', null],
            'jacket_present' => ['Наружный кожух компенсатора', 'boolean', null],
        ],
        'shell_geometry' => [
            'shell_form' => ['Форма обечайки / пояса', 'text', null],
            'small_diameter_mm' => ['Малый диаметр обечайки, мм', 'positive', null],
            'small_thickness_mm' => ['Толщина малого конца обечайки, мм', 'positive', null],
        ],
    ];

    public const KIND_CODES = ['head_geometry' => ['head'], 'end_connection' => ['nozzle', 'pipe'], 'shell_geometry' => ['shell']];

    public const TYPE_CODES = [
        'head_geometry' => ['head'],
        'fitting_geometry' => ['elbow', 'return_bend', 'reducer', 'tee', 'branch_pipe'],
        'end_connection' => ['nozzle', 'pipe', 'boss', 'branch_pipe'],
        'boss_geometry' => ['boss'],
        'valve_details' => ['valve'],
        'tube_geometry' => ['heat_exchange_tube'],
        'coil_geometry' => ['coil_body'],
        'lens_geometry' => ['lens'],
        'expansion_joint' => ['lens_expansion_joint', 'bellows_expansion_joint'],
        'shell_geometry' => ['shell', 'shell_course'],
    ];

    public const OPTIONS = [
        'tube_form' => ['прямые', 'U-образные'],
        'coil_form' => ['спиральный', 'винтовой', 'зигзагообразный', 'секционный'],
        'joint_kind' => ['осевой', 'угловой'],
        'shell_form' => ['цилиндрический', 'конический'],
    ];

    public static function allowed(string $block, string $kind, ?string $typeCode): bool
    {
        return in_array($kind, self::KIND_CODES[$block] ?? [], true) || in_array($typeCode, self::TYPE_CODES[$block], true);
    }

    public static function rules(): array
    {
        $rules = [];
        foreach (self::BLOCKS as $block => $fields) {
            $rules['structure.*.'.$block] = 'sometimes|nullable|array:'.implode(',', array_keys($fields));
            foreach ($fields as $field => [$label, $type]) {
                $rules['structure.*.'.$block.'.'.$field] = 'sometimes|nullable|'.match ($type) {
                    'boolean' => 'boolean', 'positive' => 'numeric|gt:0|max:100000000',
                    'integer' => 'integer|min:1|max:1000000',
                    'angle' => 'numeric|min:0|max:360', default => 'string|max:255',
                };
                if (isset(self::OPTIONS[$field])) {
                    $rules['structure.*.'.$block.'.'.$field] .= '|in:'.implode(',', self::OPTIONS[$field]);
                }
            }
        }

        return $rules;
    }

    /** @return array<string, list<array{key:string,label:string,type:string,type_codes:array<int,string>,kind_codes:array<int,string>,options:array<int,string>|null}>> */
    public static function metadata(): array
    {
        $result = [];
        foreach (self::BLOCKS as $block => $fields) {
            foreach ($fields as $key => [$label, $type, $types]) {
                $result[$block][] = ['key' => $key, 'label' => $label, 'type' => $type, 'type_codes' => $types ?? self::TYPE_CODES[$block], 'kind_codes' => self::KIND_CODES[$block] ?? [], 'options' => self::OPTIONS[$key] ?? null];
            }
        }

        return $result;
    }
}
