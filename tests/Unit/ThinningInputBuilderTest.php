<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\ThinningInputBuilder;
use App\Modules\Rbi\Infrastructure\InputReferenceTables;
use PHPUnit\Framework\TestCase;

class ThinningInputBuilderTest extends TestCase
{
    public function test_built_inputs_and_numerical_results_match_the_independent_original_kernel(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thinning-builder-reference.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            $result = $this->build($case['source']);
            $this->assertTrue($result['ready'], $case['name']);
            foreach ($case['expected_input'] as $key => $value) {
                if (is_int($value) || is_float($value)) {
                    $this->assertEqualsWithDelta($value, $result['input'][$key], max(1e-12, abs($value) * 1e-11), $case['name'].' '.$key);
                } else {
                    $this->assertEquals($value, $result['input'][$key], $case['name'].' '.$key);
                }
            }
            foreach ($case['expected'] as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $index => $number) {
                        $this->assertEqualsWithDelta($number, $result['calculation']['res'][$key][$index], max(1e-12, abs($number) * 1e-11), $case['name'].' '.$key);
                    }
                } elseif (is_int($value) || is_float($value)) {
                    $this->assertEqualsWithDelta($value, $result['calculation']['res'][$key], max(1e-12, abs($value) * 1e-11), $case['name'].' '.$key);
                } else {
                    $this->assertSame($value, $result['calculation']['res'][$key], $case['name'].' '.$key);
                }
            }
        }
    }

    private function source(): array
    {
        return ['analysis_on' => '2030-01-01', 'commissioned_on' => '2010-01-01', 'pipeline' => false, 'spherical_vessel' => false,
            'applicability' => ['state' => 'active', 'sources' => [['mechanism' => 'hcl', 'side' => 'internal', 'state' => 'active']]],
            'component' => ['type_code' => 'shell', 'diameter_mm' => 1220, 'seam_type' => null], 'component_rbi' => ['weld_efficiency' => 1],
            'passport' => ['temperature_design_c' => 20, 'pressure_design_mpa' => 0.5, 'corrosion_monitoring' => 'отсутствует', 'design_corrosion_mm_year' => 0.1],
            'material' => ['re_nom' => 235, 'rm_nom' => 370, 'stress' => ['rows' => [['t' => 20, 'gost' => 140]]]],
            'measurement' => ['nominal_mm' => 12, 'rejection_mm' => 4, 'rejection_source' => 'passport', 'series' => [
                ['stage_id' => 'first', 'date' => '2018-01-01', 'values' => ['min' => 11]], ['stage_id' => 'last', 'date' => '2024-01-01', 'values' => ['min' => 10]]]],
            'inspection_counts' => ['A' => 0, 'B' => 2, 'C' => 1, 'D' => 0, 'E' => 7]];
    }

    private function build(array $source): array
    {
        $reference = json_decode(file_get_contents(__DIR__.'/../../resources/rbi/reference-tables-v1.json'), true, flags: JSON_THROW_ON_ERROR);

        return (new ThinningInputBuilder)->build($source, $reference, (new InputReferenceTables)->snapshot());
    }

    public function test_complete_sources_build_a_real_kernel_result_with_explicit_assumptions_and_provenance(): void
    {
        $result = $this->build($this->source());
        $this->assertTrue($result['ready']);
        $this->assertFalse($result['excluded']);
        $this->assertSame(1 / 6, $result['input']['CrM']);
        $this->assertSame(10, $result['input']['trdiM']);
        $this->assertSame(1, $result['input']['E']);
        $this->assertSame(2, $result['input']['alpha']);
        $this->assertSame(['A' => 0, 'B' => 2, 'C' => 1, 'D' => 0], $result['input']['N']);
        $this->assertSame([0.7, 0.2, 0.1], $result['input']['prp']);
        $this->assertSame('prior_confidence_default_med', $result['assumptions'][0]['code']);
        $this->assertSame('measured_element_minimum', $result['source']['measurements']['rate_source']);
        $this->assertTrue($result['calculation']['ready']);
        $this->assertGreaterThanOrEqual(0.1, $result['calculation']['res']['DF']);
    }

    public function test_unknown_settings_and_potential_mechanism_prevent_confirmation_without_inventing_a_zero(): void
    {
        $source = $this->source();
        $source['component_rbi'] = [];
        $source['passport']['corrosion_monitoring'] = null;
        $source['inspection_counts'] = null;
        $source['applicability']['state'] = 'potential';
        $result = $this->build($source);
        $this->assertFalse($result['ready']);
        $this->assertNull($result['calculation']);
        foreach (['E', 'corrosion_monitoring', 'N.A', 'N.B', 'N.C', 'N.D', 'mechanism_applicability'] as $missing) {
            $this->assertContains($missing, $result['missing']);
        }
        $inactive = (new ThinningInputBuilder)->build(['applicability' => ['state' => 'inactive']], [], []);
        $this->assertTrue($inactive['ready']);
        $this->assertTrue($inactive['excluded']);
        $this->assertNull($inactive['calculation']);
    }

    public function test_ordinary_invalid_dimensions_remain_visible_and_do_not_run_the_kernel(): void
    {
        $source = $this->source();
        $source['component']['diameter_mm'] = 0;
        $source['passport']['pressure_design_mpa'] = -1;
        $source['inspection_counts']['B'] = 0.5;
        $result = $this->build($source);
        $this->assertFalse($result['ready']);
        $this->assertSame(0, $result['input']['D']);
        $this->assertContains('D', $result['invalid']);
        $this->assertContains('P', $result['invalid']);
        $this->assertContains('N.B', $result['invalid']);
        $this->assertNull($result['calculation']);
    }
}
