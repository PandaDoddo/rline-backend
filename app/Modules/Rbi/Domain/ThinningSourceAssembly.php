<?php

namespace App\Modules\Rbi\Domain;

use App\Modules\Equipment\Domain\ComponentParameterScope;
use App\Modules\Equipment\Domain\ComponentTree;
use App\Modules\Shared\Domain\ContentHash;

/** Select independent physical owners before the numerical builder; all arguments are read snapshots. */
final class ThinningSourceAssembly
{
    public const VERSION = 'df-thin-source-assembly-v1';

    public function assemble(array $equipment, array $scopes, array $mechanisms, array $condition, array $categories, array $settings, array $materials, string $analysisOn): array
    {
        RbiDate::decimal($analysisOn);
        $components = array_column($equipment['components'], null, 'id');
        $tree = new ComponentTree(array_values($components));
        $scopeValues = [];
        foreach ($scopes as $scope) {
            $values = (array) $scope['values'];
            foreach ((array) ($scope['annotations'] ?? []) as $field => $annotation) {
                $annotation = (array) $annotation;
                if (isset($annotation['state'])) {
                    $values[$field] = null;
                }
            }
            $scopeValues[$scope['kind'].':'.($scope['component_id'] ?? '')] = $values;
        }
        $passport = $equipment['passport'];
        foreach ($equipment['passport_qualifiers'] ?? [] as $field => $qualifier) {
            if (isset($qualifier['state'])) {
                $passport[$field] = null;
            }
        }
        $measurements = array_column($condition['elements'], null, 'id');
        $inspectionRows = array_column($categories['elements'], null, 'component_id');
        $settings = array_column($settings, null, 'component_id');
        $result = [];
        foreach ($mechanisms['elements'] as $element) {
            $id = $element['component_id'];
            $component = $components[$id];
            $scope = ComponentParameterScope::resolve($tree, $id);
            $parameters = $scope['kind'] === 'vessel' ? $passport : ($scopeValues[$scope['key']] ?? []);
            $local = [];
            $owners = [];
            // E/confidence belong to the physical holder; FIP/FDL flags belong to its root component (old e.c).
            foreach (['weld_efficiency', 'injection_point', 'dead_leg', 'confidence'] as $field) {
                $owner = in_array($field, ['injection_point', 'dead_leg'], true) ? $tree->root($id)['id'] : $id;
                if (($settings[$owner][$field] ?? null) !== null) {
                    $local[$field] = $settings[$owner][$field];
                    $owners[$field] = $owner;
                }
            }
            $materialName = $tree->value($id, 'material');
            $material = is_string($materialName) ? ($materials[trim($materialName)] ?? null) : null;
            $measurement = $measurements[$id] ?? ['nominal_mm' => $tree->value($id, 'nominal_thickness_mm'),
                'rejection_mm' => $tree->value($id, 'rejection_epb_mm') ?? $tree->value($id, 'rejection_passport_mm'),
                'rejection_source' => $tree->value($id, 'rejection_epb_mm') !== null ? 'epb' : ($tree->value($id, 'rejection_passport_mm') !== null ? 'passport' : null), 'series' => []];
            // Do not pass CorrosionCalculator rates: RBI uses its independently verified time convention.
            $measurement = ['nominal_mm' => $measurement['nominal_mm'], 'rejection_mm' => $measurement['rejection_mm'], 'rejection_source' => $measurement['rejection_source'],
                'series' => array_map(fn ($row) => ['stage_id' => $row['stage_id'], 'date' => $row['date'], 'values' => ['min' => $row['values']['min']], 'complete' => $row['complete']], $measurement['series'])];
            $type = $component['type_code'] ?? null;
            $source = ['analysis_on' => $analysisOn, 'commissioned_on' => $passport['commissioned_on'] ?? null,
                'pipeline' => $equipment['pipeline'], 'spherical_vessel' => $equipment['spherical_vessel'],
                'applicability' => (new DamageFactorApplicability)->resolve($element, $type)['factors']['thin'],
                'passport' => $parameters, 'component' => ['type_code' => $type, 'diameter_mm' => $tree->value($id, 'diameter_mm'), 'seam_type' => $this->seam($tree, $id)],
                'component_rbi' => $local, 'confidence' => $local['confidence'] ?? null, 'material' => $material['values'] ?? null, 'measurement' => $measurement,
                'inspection_counts' => $inspectionRows[$id]['groups']['thin']['counts'] ?? []];
            $provenance = ['equipment_id' => $equipment['id'], 'equipment_version' => $equipment['version'], 'component_id' => $id,
                'parameter_scope' => $scope['key'], 'settings_owners' => $owners, 'material_ref' => $material === null ? null : array_diff_key($material, ['values' => true]),
                'material_name' => $materialName, 'mechanism_signature' => $element['calculation_signature'] ?? null];
            $result[] = ['component_id' => $id, 'code' => $component['code'], 'name' => $component['name'], 'scope_id' => $element['scope_id'],
                'source' => $source, 'provenance' => $provenance, 'source_signature' => ContentHash::of([$source, $provenance, self::VERSION])];
        }

        return $result;
    }

    private function seam(ComponentTree $tree, string $id): ?string
    {
        foreach ($tree->lineage($id) as $row) {
            if (($row['construction']['seam_type'] ?? null) !== null) {
                return $row['construction']['seam_type'];
            }
        }

        return null;
    }
}
