<?php

namespace App\Modules\Rbi\Application;

use App\Modules\Catalogs\Models\SteelGrade;
use App\Modules\Diagnostics\Application\EquipmentInspectionCategories;
use App\Modules\Diagnostics\Application\EquipmentVisualInspections;
use App\Modules\Diagnostics\Domain\InspectionCategoryAssessment;
use App\Modules\Diagnostics\Domain\VisualInspectionAssessment;
use App\Modules\Equipment\Application\ComponentSpecifications;
use App\Modules\Equipment\Application\EquipmentOperatingScopes;
use App\Modules\Equipment\Application\EquipmentThickness;
use App\Modules\Equipment\Application\MechanismProjection;
use App\Modules\Equipment\Domain\CorrosionCalculator;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\ProcessFlows\Application\EquipmentFlowContacts;
use App\Modules\Rbi\Domain\ThinningInputBuilder;
use App\Modules\Rbi\Domain\ThinningSourceAssembly;
use App\Modules\Rbi\Infrastructure\InputReferenceTables;
use App\Modules\Rbi\Infrastructure\ReferenceTables;
use App\Modules\Shared\Domain\ContentHash;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Read-only assessment from one transaction snapshot. No HTTP controller or saved result is an input. */
final class EquipmentThinningAssessment
{
    public function read(Equipment $equipment, string $analysisOn): array
    {
        if (DB::connection('tenant')->transactionLevel() === 0) {
            throw new LogicException('Thinning assessment requires a consistent source transaction.');
        }
        $equipment->loadMissing([...ComponentSpecifications::eagerLoads(), 'inspections']);
        $components = $equipment->components->map(fn ($component) => [...$component->toArray(), ...app(ComponentSpecifications::class)->read($component)])->all();
        $diagnostics = $equipment->inspections->toArray();
        $pipeline = config('equipment.profiles')[$equipment->profile_code]['g'] === 'pipe';
        $scopes = app(EquipmentOperatingScopes::class)->read($equipment);
        $thickness = app(EquipmentThickness::class)->read($equipment);
        $flows = app(EquipmentFlowContacts::class)->read($equipment);
        $visual = app(EquipmentVisualInspections::class)->read($equipment);
        $visual = array_replace($visual, app(VisualInspectionAssessment::class)->evaluate($components, $visual, $flows, $equipment->passport, $scopes));
        $mechanisms = app(MechanismProjection::class)->read($equipment, $flows, $scopes, $visual);
        $categories = app(EquipmentInspectionCategories::class)->read($equipment);
        // An inspection performed after analysis must not reduce the earlier DF.
        $categories['stages'] = array_values(array_filter($categories['stages'], fn ($row) => $row['source_date'] !== null && $row['source_date'] <= $analysisOn));
        $categoryThickness = [...$thickness, 'stages' => array_values(array_filter($thickness['stages'], fn ($row) => $row['measured_on'] !== null && $row['measured_on'] <= $analysisOn))];
        $categories = app(InspectionCategoryAssessment::class)->evaluate($categories, $categoryThickness, $diagnostics, $visual, $pipeline, $mechanisms['activity']);
        $condition = app(CorrosionCalculator::class)->calculate($equipment->passport, $components, $thickness, $diagnostics, ! $pipeline);
        $names = array_values(array_unique(array_filter(array_column($components, 'material'), fn ($name) => is_string($name) && trim($name) !== '')));
        $materials = [];
        foreach (SteelGrade::whereIn('label', array_map(trim(...), $names))->where('status', 'active')->orderBy('id')->get()->groupBy('label') as $name => $matches) {
            if ($matches->count() === 1) {
                $grade = $matches->first();
                $materials[$name] = ['id' => $grade->id, 'version' => $grade->version, 'values' => $grade->effective()];
            }
        }
        $sources = app(ThinningSourceAssembly::class)->assemble(['id' => $equipment->id, 'version' => $equipment->version, 'components' => $components,
            'passport' => $equipment->passport, 'passport_qualifiers' => $equipment->passport_qualifiers, 'pipeline' => $pipeline, 'spherical_vessel' => $equipment->profile_code === 'ves_sphere'],
            $scopes, $mechanisms, $condition, $categories, app(EquipmentRbiSettings::class)->read($equipment), $materials, $analysisOn);
        $reference = app(ReferenceTables::class)->snapshot();
        $inputReference = app(InputReferenceTables::class)->snapshot();
        $rows = [];
        foreach ($sources as $row) {
            $rows[] = [...$row, 'assessment' => app(ThinningInputBuilder::class)->build($row['source'], $reference, $inputReference)];
        }
        $versions = ['source' => ThinningSourceAssembly::VERSION, 'builder' => ThinningInputBuilder::VERSION,
            'reference' => $reference['version'], 'input_reference' => $inputReference['version'],
            'reference_signature' => ContentHash::of([$reference, $inputReference])];

        return ['equipment_id' => $equipment->id, 'equipment_version' => $equipment->version, 'analysis_on' => $analysisOn, 'method' => 'thin',
            'versions' => $versions, 'source_signature' => ContentHash::of([$analysisOn, array_column($rows, 'source_signature'), $versions]), 'elements' => $rows,
            'accepted_run' => false, 'source_policy' => 'current_facts_with_measurement_and_inspection_date_cutoff'];
    }
}
