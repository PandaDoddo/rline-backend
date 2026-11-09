<?php

namespace Tests\Unit;

use App\Modules\Equipment\Domain\CorrosionCalculator;
use PHPUnit\Framework\TestCase;

class CorrosionCalculatorTest extends TestCase
{
    private function fixture(): array
    {
        $components = [
            ['id' => 'shell', 'parent_id' => null, 'code' => 'K-01', 'name' => 'Обечайка', 'is_coil' => false, 'status' => 'active', 'nominal_thickness_mm' => 12, 'rejection_passport_mm' => 7, 'rejection_epb_mm' => 8],
            ['id' => 'coil', 'parent_id' => null, 'code' => 'K-02', 'name' => 'Змеевик', 'is_coil' => true, 'status' => 'active', 'nominal_thickness_mm' => 5, 'rejection_passport_mm' => 4],
        ];
        $cmls = [['id' => 'a', 'component_id' => 'shell', 'point_count' => 2], ['id' => 'b', 'component_id' => 'shell', 'point_count' => 2], ['id' => 'c', 'component_id' => 'coil', 'point_count' => 1]];
        $stages = [
            ['id' => 's1', 'inspection_id' => 'i1', 'measured_on' => '2020-01-01', 'measurements' => [['cml_id' => 'a', 'points' => [10, 12]], ['cml_id' => 'b', 'points' => [null, 11]], ['cml_id' => 'c', 'points' => [4.5]]]],
            ['id' => 'gap', 'inspection_id' => 'gap', 'measured_on' => '2021-01-01', 'measurements' => [['cml_id' => 'c', 'points' => [4.2]]]],
            ['id' => 's2', 'inspection_id' => 'i2', 'measured_on' => '2022-01-01', 'measurements' => [['cml_id' => 'a', 'points' => [9, 11]], ['cml_id' => 'b', 'points' => [10, 10]], ['cml_id' => 'c', 'points' => [3]]]],
        ];
        $inspections = [['id' => 'i1', 'performed_on' => '2020-01-02'], ['id' => 'i2', 'performed_on' => '2021-12-31'], ['id' => 'plan', 'kind' => 'ТО-1', 'performed_on' => null, 'planned_on' => '2025-01-01']];

        return [['commissioned_on' => '2010-06-01'], $components, ['cmls' => $cmls, 'stages' => $stages], $inspections];
    }

    public function test_nested_coil_assembly_inherits_nearest_thickness_and_demount_hides_descendants(): void
    {
        [$passport, $components, $thickness, $inspections] = $this->fixture();
        $components[] = ['id' => 'assembly', 'parent_id' => 'coil', 'code' => 'K-02.S1', 'name' => 'Сборка', 'is_coil' => false, 'status' => 'active', 'nominal_thickness_mm' => 4.8];
        $components[] = ['id' => 'leaf', 'parent_id' => 'assembly', 'code' => 'K-02.S1.S1', 'name' => 'Труба', 'is_coil' => false, 'status' => 'active'];
        $thickness['cmls'][2]['component_id'] = 'leaf';
        $result = (new CorrosionCalculator)->calculate($passport, $components, $thickness, $inspections);
        $leaf = array_values(array_filter($result['elements'], fn ($row) => $row['id'] === 'leaf'))[0];
        $this->assertSame('coil', $leaf['scope_id']);
        $this->assertSame(4.8, $leaf['nominal_mm']);
        $this->assertEquals(4.0, $leaf['rejection_mm']);
        $this->assertSame('rejected', $leaf['latest']['status']);
        $components[2]['status'] = 'removed';
        $result = (new CorrosionCalculator)->calculate($passport, $components, $thickness, $inspections);
        $this->assertNotContains('leaf', array_column($result['elements'], 'id'));
        $this->assertNotContains('c', array_column($result['cmls'], 'id'));
    }

    public function test_element_aggregation_gaps_bases_and_independent_coil(): void
    {
        $result = (new CorrosionCalculator)->calculate(...$this->fixture());
        $element = $result['elements'][0];
        $this->assertCount(2, $element['series']);
        $this->assertFalse($element['series'][0]['complete']);
        $this->assertEquals(['min' => 10, 'ave' => 11, 'max' => 12], $element['series'][0]['values']);
        $latest = $element['latest'];
        $this->assertTrue($latest['complete']);
        $this->assertEquals(0.25, $latest['rates']['min']['nominal']);
        $this->assertEquals(0.5, $latest['rates']['min']['first']);
        $this->assertEquals(0.5, $latest['rates']['min']['short']);
        $this->assertEquals(4, $latest['life']['min']['nominal']);
        $this->assertEquals(2, $latest['life']['min']['short']);
        $this->assertTrue($element['life_warning']);
        $this->assertTrue($latest['date_anomaly']);
        $this->assertSame('epb', $element['rejection_source']);
        $this->assertEquals(8, $element['rejection_mm']);
        $this->assertSame(0, $result['scopes'][0]['rejected']);
        $this->assertSame(1, $result['scopes'][1]['rejected']);
        $this->assertEquals(75, $latest['wear_percent']['min']);
        $this->assertCount(3, $result['cmls']);
        $this->assertSame('shell', $result['cmls'][0]['element_id']);
        $this->assertSame('coil', $result['cmls'][2]['scope_id']);
        $this->assertEqualsWithDelta(1 / 6, $result['cmls'][1]['latest']['rates']['min']['nominal'], 0.0000001);
    }

    public function test_unknown_planned_point_count_does_not_claim_a_complete_element_measurement(): void
    {
        [$passport, $components, $thickness, $inspections] = $this->fixture();
        $thickness['cmls'][0]['point_count'] = null;
        $result = (new CorrosionCalculator)->calculate($passport, $components, $thickness, $inspections);
        $shell = collect($result['elements'])->firstWhere('id', 'shell');
        $this->assertFalse($shell['latest']['complete']);
        $this->assertEquals(9, $shell['latest']['values']['min']);
        $this->assertEquals(10, $shell['latest']['values']['ave']);
    }

    public function test_missing_inputs_negative_rates_and_explicit_zero(): void
    {
        [$passport, $components, $thickness, $inspections] = $this->fixture();
        $components[0]['rejection_epb_mm'] = null;
        $components[0]['rejection_passport_mm'] = null;
        $result = (new CorrosionCalculator)->calculate([], $components, $thickness, $inspections);
        $this->assertNull($result['elements'][0]['latest']['life']['min']['nominal']);
        $this->assertNull($result['elements'][0]['latest']['rates']['min']['nominal']);
        $this->assertSame('unknown', $result['elements'][0]['latest']['status']);
        $components[0]['rejection_passport_mm'] = 8;
        $thickness['stages'][2]['measurements'][0]['points'] = [13, 13];
        $thickness['stages'][2]['measurements'][1]['points'] = [13, 13];
        $result = (new CorrosionCalculator)->calculate($passport, $components, $thickness, $inspections);
        $this->assertLessThan(0, $result['elements'][0]['latest']['rates']['min']['short']);
        $this->assertSame('infinite', $result['elements'][0]['latest']['life']['min']['short']);
        $this->assertEquals(0, $result['elements'][0]['latest']['wear_percent']['min']);
        $thickness['stages'][2]['measurements'][0]['points'] = [null, 0];
        $result = (new CorrosionCalculator)->calculate($passport, $components, $thickness, $inspections);
        $this->assertEquals(0, $result['elements'][0]['latest']['values']['min']);
        $this->assertEquals(0, $result['elements'][0]['latest']['life']['min']['short']);
        $components[0]['status'] = 'removed';
        $result = (new CorrosionCalculator)->calculate($passport, $components, $thickness, $inspections);
        $this->assertCount(1, $result['elements']);
        $this->assertSame('coil', $result['elements'][0]['scope_id']);
    }

    public function test_periodicity_history_uses_vessel_elements_and_marks_a_change(): void
    {
        [$passport, $components, $thickness, $inspections] = $this->fixture();
        $passport += ['ntd' => 'ФНП ОРПД', 'registration_authority' => 'ОТН', 'design_corrosion_mm_year' => 0.05];
        $thickness['stages'][0]['measurements'][0]['points'] = [11.5, 12];
        $thickness['stages'][0]['measurements'][1]['points'] = [null, 11.5];
        $thickness['stages'][2]['measurements'][2]['points'] = [0];
        $result = (new CorrosionCalculator)->calculate($passport, $components, $thickness, $inspections);
        $periodicity = $result['periodicity'];
        $this->assertSame(2, $periodicity['history'][0]['otn_years']);
        $this->assertSame(1, $periodicity['history'][1]['otn_years']);
        $this->assertTrue($periodicity['history'][1]['changed']);
        $this->assertEquals(1.25, $periodicity['current']['accepted_speed']);
        $this->assertNull($periodicity['current']['rtn_years']);
    }
}
