<?php

namespace App\Modules\DataExchange\Application;

use App\Modules\Diagnostics\Domain\VisualInspectionSchema;
use App\Modules\Equipment\Models\Equipment;
use Illuminate\Validation\ValidationException;

/** Resolve source dates only after the equipment's diagnostic history exists. */
final class DatasetVisualInspections
{
    public function input(Equipment $equipment, array $source): array
    {
        $diagnostics = $equipment->inspections()->get();
        $priority = ['НО' => 0, 'ТО-1' => 1, 'ТО-2' => 2, 'ЭПБ' => 3];
        $dates = [];
        $stages = [];
        foreach ($source['stages'] ?? [] as $index => $stage) {
            $date = $stage['source_date'];
            if (isset($dates[$date])) {
                throw ValidationException::withMessages(['visual_inspections.stages.'.$index => 'Исходная дата ВИК не должна повторяться.']);
            }
            $dates[$date] = true;
            $inspection = $diagnostics->filter(fn ($row) => $row->performed_on?->format('Y-m-d') === $date && isset($priority[$row->kind]))
                ->sort(fn ($a, $b) => $priority[$b->kind] <=> $priority[$a->kind] ?: strcmp($a->id, $b->id))->first();
            $stages[] = ['id' => $stage['id'], 'inspection_id' => $inspection?->id, 'reference_date' => $inspection ? null : $date,
                'measured_on' => $stage['measured_on'], 'verified' => false, 'observations' => $stage['observations'] ?? []];
        }

        return ['schema_version' => VisualInspectionSchema::VERSION, 'stages' => $stages, 'insulation_overrides' => $source['insulation_overrides'] ?? []];
    }
}
