<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\ThinningSourceAssembly;
use PHPUnit\Framework\TestCase;

class ThinningSourceAssemblyTest extends TestCase
{
    private function fixture(): array
    {
        $base = ['parent_id' => null, 'kind' => 'shell', 'code' => 'K-01', 'name' => 'Element', 'is_coil' => false, 'status' => 'active', 'type_code' => 'shell', 'material' => 'Steel', 'diameter_mm' => 1000.0, 'nominal_thickness_mm' => 10.0, 'rejection_passport_mm' => 5.0];
        $components = [['id' => 'shell', ...$base, 'construction' => ['seam_type' => 'welded']], ['id' => 'tube-head', ...$base, 'flow_space' => 'tube', 'type_code' => 'head'],
            ['id' => 'coil', ...$base, 'is_coil' => true], ['id' => 'child', ...$base, 'parent_id' => 'coil', 'material' => null], ['id' => 'unresolved', ...$base, 'material' => 'steel']];
        $equipment = ['id' => 'equipment', 'version' => 7, 'components' => $components, 'pipeline' => false, 'spherical_vessel' => false,
            'passport' => ['commissioned_on' => '2000-01-01', 'pressure_design_mpa' => 2.0, 'temperature_design_c' => 200.0, 'design_corrosion_mm_year' => 0.1],
            'passport_qualifiers' => ['temperature_design_c' => ['state' => 'unknown']]];
        $mechanisms = ['elements' => array_map(fn ($row) => ['component_id' => $row['id'], 'scope_id' => in_array($row['id'], ['coil', 'child'], true) ? 'coil' : 'vessel', 'active' => true, 'mechanisms' => []], $components)];
        $scopes = [['kind' => 'tube', 'component_id' => null, 'values' => (object) ['pressure_design_mpa' => 0.0], 'annotations' => (object) []],
            ['kind' => 'coil', 'component_id' => 'coil', 'values' => ['pressure_design_mpa' => 4.0], 'annotations' => ['pressure_design_mpa' => (object) ['state' => 'unknown']]]];

        return [$equipment, $scopes, $mechanisms, ['elements' => []], ['elements' => []],
            [['component_id' => 'shell', 'weld_efficiency' => 0.85], ['component_id' => 'coil', 'weld_efficiency' => 0.7, 'injection_point' => true, 'dead_leg' => true]],
            ['Steel' => ['id' => 'grade', 'version' => 2, 'values' => ['kind' => 'cs']]], '2026-11-10'];
    }

    public function test_parameter_and_setting_owners_do_not_leak_between_shell_tube_and_coil(): void
    {
        $rows = array_column((new ThinningSourceAssembly)->assemble(...$this->fixture()), null, 'component_id');
        $this->assertNull($rows['shell']['source']['passport']['temperature_design_c']);
        $this->assertSame(0.0, $rows['tube-head']['source']['passport']['pressure_design_mpa']);
        $this->assertArrayNotHasKey('temperature_design_c', $rows['tube-head']['source']['passport']);
        $this->assertNull($rows['coil']['source']['passport']['pressure_design_mpa']);
        $this->assertArrayNotHasKey('weld_efficiency', $rows['child']['source']['component_rbi']);
        $this->assertTrue($rows['child']['source']['component_rbi']['dead_leg']);
        $this->assertTrue($rows['child']['source']['component_rbi']['injection_point']);
        $this->assertSame('coil', $rows['child']['provenance']['settings_owners']['injection_point']);
        $this->assertSame('grade', $rows['child']['provenance']['material_ref']['id']);
        $this->assertNull($rows['unresolved']['source']['material']);
        $this->assertSame('welded', $rows['shell']['source']['component']['seam_type']);
        $this->assertSame([], $rows['shell']['source']['inspection_counts']);
        $this->assertSame('2000-01-01', $rows['coil']['source']['commissioned_on']);
    }

    public function test_minimum_series_keep_zero_and_drop_other_time_convention_rates_and_signature_tracks_changes(): void
    {
        $fixture = $this->fixture();
        $fixture[3]['elements'] = [['id' => 'shell', 'nominal_mm' => 10.0, 'rejection_mm' => 5.0, 'rejection_source' => 'passport',
            'series' => [['stage_id' => 'stage', 'date' => '2020-01-01', 'complete' => false, 'values' => ['min' => 0.0], 'rates' => ['min' => ['nominal' => 999]]]]]];
        $builder = new ThinningSourceAssembly;
        $rows = $builder->assemble(...$fixture);
        $this->assertSame(0.0, $rows[0]['source']['measurement']['series'][0]['values']['min']);
        $this->assertArrayNotHasKey('rates', $rows[0]['source']['measurement']['series'][0]);
        $this->assertSame($rows, $builder->assemble(...$fixture));
        $fixture[1][0]['values']->pressure_design_mpa = 1.0;
        $changed = $builder->assemble(...$fixture);
        $this->assertSame($rows[0]['source_signature'], $changed[0]['source_signature']);
        $this->assertNotSame($rows[1]['source_signature'], $changed[1]['source_signature']);
    }
}
