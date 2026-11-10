<?php

namespace Tests\Unit;

use App\Modules\Diagnostics\Domain\VisualInspectionAssessment;
use PHPUnit\Framework\TestCase;

class VisualInspectionAssessmentTest extends TestCase
{
    private function components(): array
    {
        return [
            ['id' => 'body', 'parent_id' => null, 'code' => 'K-01', 'name' => 'Сосуд', 'kind' => 'shell', 'is_coil' => false, 'status' => 'active', 'nominal_thickness_mm' => 10],
            ['id' => 'coil', 'parent_id' => null, 'code' => 'K-02', 'name' => 'Змеевик', 'kind' => 'coil', 'is_coil' => true, 'status' => 'active', 'nominal_thickness_mm' => 2],
        ];
    }

    private function stage(string $id, string $date, array $rows, bool $verified = false): array
    {
        return ['id' => $id, 'source_date' => $date, 'measured_on' => $date, 'diagnostic_on' => $date, 'verified' => $verified, 'observations' => $rows];
    }

    public function test_vessel_and_coil_have_independent_exposure_protection_depth_and_scope_totals(): void
    {
        $facts = ['current_element_ids' => ['body', 'coil'], 'insulation_overrides' => [['component_id' => 'coil', 'is_insulated' => false]], 'stages' => [
            $this->stage('first', '2020-01-01', [['component_id' => 'body', 'values' => ['corrosion_present' => true, 'depth_mm' => .4, 'insulation_condition' => 'average']]]),
            $this->stage('last', '2024-01-01', [['component_id' => 'body', 'values' => ['corrosion_present' => true, 'depth_mm' => .2, 'partial' => true]], ['component_id' => 'coil', 'values' => ['corrosion_present' => true, 'depth_mm' => 2, 'insulation_condition' => 'poor']]]),
        ]];
        $facts['stages'][1]['diagnostic_on'] = '2023-12-31';
        $flows = ['effective' => [['component_id' => 'body', 'side' => 'insulation', 'mode' => 'assign', 'contact_id' => 'contact']], 'contacts' => [['id' => 'contact', 'flows' => [['stale' => false, 'flow_status' => 'active']]]]];
        $result = (new VisualInspectionAssessment)->evaluate($this->components(), $facts, $flows, ['external_coating_present' => 'отсутствует']);
        $elements = array_column($result['elements'], null, 'component_id');
        $this->assertTrue($elements['body']['insulated']);
        $this->assertFalse($elements['coil']['insulated']);
        $this->assertFalse($elements['body']['coating_applicable']);
        $this->assertNull($elements['coil']['coating_applicable']);
        $this->assertSame('C', $result['stages'][1]['observations'][0]['category']);
        $this->assertSame('cui', $result['stages'][1]['observations'][0]['kind']);
        $this->assertSame(2.0, $result['stages'][1]['observations'][0]['depth_percent']);
        $this->assertSame('ext', $result['stages'][1]['observations'][1]['kind']);
        $codes = array_column($result['anomalies'], 'code');
        foreach (['date_after_diagnosis', 'depth_decreased', 'missing_insulation_condition', 'depth_reaches_nominal', 'condition_without_insulation'] as $code) {
            $this->assertContains($code, $codes);
        }
        $summaries = array_column($result['scope_summaries'], null, 'scope_id');
        $this->assertSame(.4, $summaries['vessel']['max_depth_mm']);
        $this->assertSame(2, $summaries['coil']['max_depth_mm']);
        $this->assertSame(1, $summaries['vessel']['corroded_elements']);
        $this->assertSame(1, $summaries['coil']['corroded_elements']);
    }

    public function test_unknown_exposure_and_unfilled_stages_never_create_absence_or_fake_observations(): void
    {
        $facts = ['current_element_ids' => ['body', 'coil'], 'insulation_overrides' => [], 'stages' => [$this->stage('empty', '2024-01-01', [])]];
        $assessment = new VisualInspectionAssessment;
        $result = $assessment->evaluate($this->components(), $facts, [], []);
        $this->assertNull($result['elements'][0]['insulated']);
        $this->assertSame(0, $result['scope_summaries'][0]['observed_elements']);
        $this->assertNull($result['scope_summaries'][0]['max_depth_mm']);
        $facts['stages'][0]['verified'] = true;
        $result = $assessment->evaluate($this->components(), $facts, [], []);
        $this->assertSame([], $result['stages'][0]['observations']);
        foreach ($result['scope_summaries'] as $scope) {
            $this->assertSame(1, $scope['observed_elements']);
            $this->assertSame(1, $scope['unknown_corrosion_elements']);
            $this->assertSame(0, $scope['corroded_elements']);
            $this->assertNull($scope['max_depth_mm']);
        }
    }

    public function test_zero_depth_stays_zero_and_unknown_type_is_not_reported_as_external_corrosion(): void
    {
        $facts = ['current_element_ids' => ['body'], 'insulation_overrides' => [], 'stages' => [$this->stage('zero', '2024-01-01', [['component_id' => 'body', 'values' => ['corrosion_present' => true, 'depth_mm' => 0]]])]];
        $result = (new VisualInspectionAssessment)->evaluate($this->components(), $facts, [], []);
        $this->assertSame(0, $result['scope_summaries'][0]['max_depth_mm']);
        $this->assertSame(0.0, $result['stages'][0]['observations'][0]['depth_percent']);
        $this->assertSame('B', $result['stages'][0]['observations'][0]['category']);
        $this->assertNull($result['stages'][0]['observations'][0]['kind']);
        $this->assertSame([], $result['anomalies']);
    }

    public function test_tube_side_heads_and_nozzles_use_tube_protection_and_never_borrow_the_vessel_coating(): void
    {
        $components = [
            ['id' => 'head', 'parent_id' => null, 'code' => 'K-01', 'name' => 'Head', 'kind' => 'head', 'flow_space' => 'tube', 'is_coil' => false, 'status' => 'active', 'position' => 0],
            ['id' => 'nozzle', 'parent_id' => null, 'code' => 'K-02', 'name' => 'Nozzle', 'kind' => 'nozzle', 'flow_space' => 'tube', 'is_coil' => false, 'status' => 'active', 'position' => 1],
            ['id' => 'shell', 'parent_id' => null, 'code' => 'K-03', 'name' => 'Shell', 'kind' => 'shell', 'is_coil' => false, 'status' => 'active', 'position' => 2],
        ];
        $facts = ['current_element_ids' => ['head', 'nozzle', 'shell'], 'insulation_overrides' => [], 'stages' => []];
        $assessment = new VisualInspectionAssessment;
        $passport = ['external_coating_present' => 'присутствует'];
        $unknown = array_column($assessment->evaluate($components, $facts, [], $passport)['elements'], null, 'component_id');
        $this->assertNull($unknown['head']['coating_applicable']);
        $this->assertNull($unknown['nozzle']['coating_applicable']);
        $this->assertTrue($unknown['shell']['coating_applicable']);
        $scopes = [['kind' => 'tube', 'component_id' => null, 'values' => (object) ['external_coating_present' => 'отсутствует']]];
        $known = array_column($assessment->evaluate($components, $facts, [], $passport, $scopes)['elements'], null, 'component_id');
        $this->assertFalse($known['head']['coating_applicable']);
        $this->assertFalse($known['nozzle']['coating_applicable']);
        $this->assertTrue($known['shell']['coating_applicable']);
    }
}
