<?php

namespace App\Modules\DataExchange\Application;

use App\Modules\Diagnostics\Application\EquipmentInspectionCategories;
use App\Modules\Diagnostics\Domain\InspectionCategorySchema;
use App\Modules\Equipment\Models\Equipment;
use Illuminate\Validation\ValidationException;

final class DatasetInspectionCategories
{
    public function input(Equipment $equipment, array $source): array
    {
        $diagnostics = $equipment->inspections()->get();
        $priority = ['УЗТ' => 0, 'НО' => 1, 'ТО-1' => 2, 'ТО-2' => 3, 'ЭПБ' => 4];
        $stages = [];
        $dates = [];
        foreach ($source['stages'] ?? [] as $index => $stage) {
            $key = $stage['group_code'].'|'.$stage['source_date'];
            if (isset($dates[$key])) {
                throw ValidationException::withMessages(['inspection_categories.stages.'.$index => 'Исходная дата повторяется в одной группе инспекций.']);
            }
            $dates[$key] = true;
            $inspection = $diagnostics->filter(fn ($row) => $row->performed_on?->format('Y-m-d') === $stage['source_date'] && in_array($row->kind, EquipmentInspectionCategories::kinds($stage['group_code']), true))
                ->sort(fn ($a, $b) => $priority[$b->kind] <=> $priority[$a->kind] ?: strcmp($a->id, $b->id))->first();
            $stages[] = ['id' => $stage['id'], 'group_code' => $stage['group_code'], 'inspection_id' => $inspection?->id, 'reference_date' => $inspection ? null : $stage['source_date'], 'observations' => $stage['observations'] ?? []];
        }

        return ['schema_version' => InspectionCategorySchema::VERSION, 'stages' => $stages];
    }
}
