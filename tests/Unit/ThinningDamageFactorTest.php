<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\ThinningDamageFactor;
use PHPUnit\Framework\TestCase;

class ThinningDamageFactorTest extends TestCase
{
    private function cases(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/thinning-reference.json'), true, flags: JSON_THROW_ON_ERROR)['cases'];
    }

    public function test_equations_shapes_corrosion_and_inspections_match_the_old_kernel(): void
    {
        $calculator = new ThinningDamageFactor;
        foreach ($this->cases() as $case) {
            $result = $calculator->calculate($case['input']);
            $this->assertTrue($result['ready']);
            foreach ($case['expected'] as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $index => $number) {
                        $this->assertEqualsWithDelta($number, $result['res'][$key][$index], max(1e-12, abs($number) * 1e-11), $key);
                    }
                } elseif (is_int($value) || is_float($value)) {
                    $this->assertEqualsWithDelta($value, $result['res'][$key], max(1e-12, abs($value) * 1e-11), $key);
                } else {
                    $this->assertSame($value, $result['res'][$key], $key);
                }
            }
        }
    }

    public function test_partial_measurement_does_not_fall_back_to_design_and_many_inspections_do_not_guess_equal_posteriors(): void
    {
        $input = $this->cases()[0]['input'];
        $input['trdiM'] = null;
        $calculator = new ThinningDamageFactor;
        $result = $calculator->calculate($input);
        $this->assertFalse($result['ready']);
        $this->assertContains('trdiM', $result['missing']);
        $this->assertNull($result['res']['DF']);
        $input = $this->cases()[0]['input'];
        $input['N'] = ['A' => 10000, 'B' => 0, 'C' => 0, 'D' => 0];
        $result = $calculator->calculate($input);
        $this->assertTrue($result['ready']);
        $this->assertSame([1.0, 0.0, 0.0], $result['res']['Pop']);
        $this->assertTrue(is_finite($result['res']['DF']));
    }
}
