<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\ProbabilityOfFailure;
use App\Modules\Rbi\Domain\RiskMatrix;
use PHPUnit\Framework\TestCase;

class ProbabilityOfFailureTest extends TestCase
{
    public function test_numeric_results_match_the_old_kernel_for_every_mechanism_and_corrosion_combination(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/pof-reference.json'), true, flags: JSON_THROW_ON_ERROR);
        $calculator = new ProbabilityOfFailure;
        foreach ($fixture['cases'] as $case) {
            $input = $case['input'];
            $result = $calculator->calculate($input['gff'], $input['pscore'], $input['ti'], $input['te'], $input['df'], $input['active']);
            $this->assertTrue($result['ready']);
            foreach ($case['expected'] as $key => $value) {
                if (is_bool($value)) {
                    $this->assertSame($value, $result['res'][$key]);
                } else {
                    $this->assertEqualsWithDelta($value, $result['res'][$key], max(1e-14, abs($value) * 1e-12), $key);
                }
            }
        }
    }

    public function test_missing_active_damage_or_management_data_cannot_become_zero_or_default_72(): void
    {
        $calculator = new ProbabilityOfFailure;
        $result = $calculator->calculate(3.06e-5, null, 'общая', 'общая', [], ['thin']);
        $this->assertFalse($result['ready']);
        $this->assertSame(['df.thin', 'management_score'], $result['missing']);
        $this->assertNull($result['res']['PoF']);
        $this->assertNull($result['res']['Fms']);
        $this->assertNull($result['res']['Dft']);
        $this->assertNull($result['res']['thin']);
        $this->assertNull((new RiskMatrix)->zone(null, 'A'));
    }

    public function test_probability_boundaries_and_all_matrix_cells_match_reference(): void
    {
        $matrix = new RiskMatrix;
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/pof-reference.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['categories'] as $case) {
            $this->assertSame($case['expected'], $matrix->probabilityCategory($case['pof']));
        }
        foreach ($fixture['matrix'] as $index => $row) {
            foreach ($row as $column => $zone) {
                $this->assertSame($zone, $matrix->zone($index + 1,chr(65 + $column)));
            }
        }
    }
}
