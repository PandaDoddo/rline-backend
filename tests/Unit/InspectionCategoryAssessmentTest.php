<?php

namespace Tests\Unit;

use App\Modules\Diagnostics\Domain\InspectionCategoryAssessment;
use PHPUnit\Framework\TestCase;

class InspectionCategoryAssessmentTest extends TestCase
{
    public function test_sparse_projection_preserves_measurement_zero_overrides_and_never_counts_unknown_or_inactive_mechanisms(): void
    {
        $stages = [];
        foreach (['thin', 'ext', 'cui', 'scc'] as $group) {
            $stages[] = ['id' => $group, 'group_code' => $group, 'source_date' => '2023-01-01', 'diagnostic_kind' => 'ТО-2', 'observations' => $group === 'scc' ? [['component_id' => 'body', 'category' => 'D', 'cracks_present' => false]] : []];
        }
        $elements = [['component_id' => 'body', 'scope_id' => 'vessel', 'current_element' => true, 'active' => true, 'insulated' => true], ['component_id' => 'coil', 'scope_id' => 'coil', 'current_element' => true, 'active' => true, 'insulated' => false]];
        $thickness = ['cmls' => [['id' => 'c1', 'component_id' => 'body'], ['id' => 'c2', 'component_id' => 'body'], ['id' => 'coil-cml', 'component_id' => 'coil']],
            'stages' => [['inspection_id' => 'inspection', 'measured_on' => '2022-12-31', 'measurements' => [['cml_id' => 'c1', 'points' => [0, null]], ['cml_id' => 'c2', 'points' => [null, null]], ['cml_id' => 'coil-cml', 'points' => [4]]]]]];
        $visual = ['elements' => $elements, 'stages' => [['source_date' => '2023-01-01', 'verified' => false, 'observations' => [['component_id' => 'body', 'values' => ['corrosion_present' => false, 'partial' => true]]]]]];
        $assessment = new InspectionCategoryAssessment;
        $unknown = $assessment->evaluate(['stages' => $stages], $thickness, [['id' => 'inspection', 'performed_on' => '2023-01-01']], $visual, false);
        $this->assertFalse($unknown['mechanisms_ready']);
        $this->assertSame('D', $unknown['stages'][0]['automatic_category']);
        $computed = array_column($unknown['stages'][0]['automatic_observations'], null, 'component_id');
        $this->assertSame('C', $computed['body']['category']);
        $this->assertSame(1, $computed['body']['measured_cmls']);
        $this->assertSame('B', $computed['coil']['category']);
        foreach ($unknown['elements'] as $element) {
            foreach ($element['groups'] as $group) {
                $this->assertSame(['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0], $group['counts']);
            }
        }
        $known = $assessment->evaluate(['stages' => $stages], $thickness, [['id' => 'inspection', 'performed_on' => '2023-01-01']], $visual, false, ['body' => ['thin' => true, 'ext' => true, 'cui' => true, 'scc' => false], 'coil' => ['thin' => true]]);
        $rows = array_column($known['elements'], null, 'component_id');
        $this->assertSame(1, $rows['body']['groups']['thin']['counts']['C']);
        $this->assertSame(1, $rows['coil']['groups']['thin']['counts']['B']);
        $this->assertSame(1, $rows['body']['groups']['cui']['counts']['C']);
        $this->assertSame(0, array_sum($rows['body']['groups']['ext']['counts']));
        $this->assertSame(0, array_sum($rows['body']['groups']['scc']['counts']));
        $this->assertSame('D', $known['stages'][3]['observations'][0]['category']);
        $stages[0]['observations'] = [['component_id' => 'body', 'category' => 'none']];
        $excluded = $assessment->evaluate(['stages' => $stages], $thickness, [['id' => 'inspection', 'performed_on' => '2023-01-01']], $visual, false, ['body' => ['thin' => true]]);
        $this->assertSame(0,array_sum($excluded['elements'][0]['groups']['thin']['counts']));
    }
}
