<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\ConsequenceOfFailure;
use PHPUnit\Framework\TestCase;

class ConsequenceOfFailureTest extends TestCase
{
    private function cases(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/cof-reference.json'), true, flags: JSON_THROW_ON_ERROR)['cases'];
    }

    private function tables(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../resources/rbi/reference-tables-v1.json'), true, flags: JSON_THROW_ON_ERROR)['tables'];
    }

    public function test_release_regimes_fires_fourteen_toxics_nfnt_and_financials_match_old(): void
    {
        foreach ($this->cases() as $index => $case) {
            $result = (new ConsequenceOfFailure)->calculate($case['input'], $this->tables());
            $this->assertTrue($result['ready'], json_encode([$index, $result['missing']]));
            foreach ($case['expected'] as $key => $value) {
                $this->assertEqualsWithDelta($value, $result['res'][$key], max(1e-9, abs($value) * 1e-11), $index.'.'.$key);
            }
        }
    }

    public function test_missing_pressure_or_costs_block_and_zero_is_explicit(): void
    {
        $case = $this->cases()[0];
        unset($case['input']['P'],$case['input']['cost']['prodcost']);
        $result = (new ConsequenceOfFailure)->calculate($case['input'], $this->tables());
        $this->assertFalse($result['ready']);
        $this->assertNull($result['res']);
        $this->assertContains('P', $result['missing']);
        $this->assertContains('cost.prodcost', $result['missing']);
        $input = $this->cases()[0]['input'];
        $input['matcost'] = 0;
        $input['proddecrease'] = 0;
        $input['cost']['outagemult'] = 0;
        $result = (new ConsequenceOfFailure)->calculate($input, $this->tables());
        $this->assertTrue($result['ready']);
        $this->assertEquals(0, $result['res']['FCc']);
        $this->assertEquals(0, $result['res']['FCp']);
    }
}
