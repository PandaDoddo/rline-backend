<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\MaterialStrengthInputs;
use PHPUnit\Framework\TestCase;

class MaterialStrengthInputsTest extends TestCase
{
    private function material(): array
    {
        return ['re_nom' => 235, 'rm_nom' => 370, 'stress' => ['rows' => [['t' => 20, 'gost' => 140, 'rd' => 130], ['t' => 100, 'gost' => 120, 'rd' => 110]]]];
    }

    public function test_vessel_and_pipe_use_their_own_standard_and_scale_both_nominal_strengths(): void
    {
        $builder = new MaterialStrengthInputs;
        $vessel = $builder->resolve($this->material(), 60, false);
        $pipe = $builder->resolve($this->material(), 60, true);
        $this->assertSame(130.0, $vessel['values']['S']);
        $this->assertEqualsWithDelta(235 * 130 / 140, $vessel['values']['YS'], 1e-12);
        $this->assertEqualsWithDelta(370 * 130 / 140, $vessel['values']['TS'], 1e-12);
        $this->assertSame(120.0, $pipe['values']['S']);
        $this->assertEqualsWithDelta(235 * 120 / 130, $pipe['values']['YS'], 1e-12);
        $this->assertSame([], $vessel['missing']);
        $this->assertSame('РД 10-249-98', $pipe['source']['standard']);
    }

    public function test_absence_and_out_of_range_remain_unknown_instead_of_using_clamped_boundary_values(): void
    {
        $builder = new MaterialStrengthInputs;
        foreach ([null, -10, 101] as $temperature) {
            $result = $builder->resolve($this->material(), $temperature, false);
            $this->assertSame(['S' => null, 'YS' => null, 'TS' => null], $result['values']);
            $this->assertSame(['S', 'YS', 'TS'], $result['missing']);
        }
        $this->assertSame(['S', 'YS', 'TS'], $builder->resolve(null, 60, false)['missing']);
        $material = $this->material();
        $material['stress']['rows'][0]['gost'] = null;
        $single = $builder->resolve($material, 100, false);
        $this->assertSame(120.0, $single['values']['S']);
        $this->assertNull($single['values']['YS']);
        $this->assertSame(['YS', 'TS'], $single['missing']);
    }

    public function test_boolean_numeric_inputs_are_rejected_instead_of_becoming_a_temperature(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MaterialStrengthInputs)->resolve($this->material(), false, false);
    }
}
