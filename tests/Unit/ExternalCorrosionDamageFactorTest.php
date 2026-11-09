<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\ExternalCorrosionDamageFactor;
use PHPUnit\Framework\TestCase;

class ExternalCorrosionDamageFactorTest extends TestCase
{
    private function cases(): array { return json_decode(file_get_contents(__DIR__.'/../Fixtures/external-corrosion-reference.json'),true,flags:JSON_THROW_ON_ERROR)['cases']; }

    public function test_external_and_cui_equations_coating_states_and_inspections_match_old(): void
    {
        $calculator=new ExternalCorrosionDamageFactor;
        foreach ($this->cases() as $case) {
            $result=$calculator->calculate($case['input'],$case['kind']); $this->assertTrue($result['ready']);
            foreach ($case['expected'] as $key=>$value) {
                if (is_array($value)) foreach ($value as $index=>$number) $this->assertEqualsWithDelta($number,$result['res'][$key][$index],max(1e-12,abs($number)*1e-11),$key);
                elseif (is_int($value)||is_float($value)) $this->assertEqualsWithDelta($value,$result['res'][$key],max(1e-12,abs($value)*1e-11),$key);
                else $this->assertSame($value,$result['res'][$key],$key);
            }
        }
    }

    public function test_unknown_cui_factor_and_partial_measured_loss_block_the_result(): void
    {
        $input=$this->cases()[0]['input']; $input['FINS']=null;
        $result=(new ExternalCorrosionDamageFactor)->calculate($input,'cui');
        $this->assertFalse($result['ready']); $this->assertContains('FINS',$result['missing']); $this->assertNull($result['res']['DF']);
        $input=$this->cases()[0]['input']; $input['Le']=null;
        $result=(new ExternalCorrosionDamageFactor)->calculate($input);
        $this->assertFalse($result['ready']); $this->assertContains('Le',$result['missing']);
    }
}
