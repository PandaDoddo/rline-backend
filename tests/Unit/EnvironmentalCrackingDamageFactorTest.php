<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\EnvironmentalCrackingDamageFactor;
use PHPUnit\Framework\TestCase;

class EnvironmentalCrackingDamageFactorTest extends TestCase
{
    private function fixture(): array { return json_decode(file_get_contents(__DIR__.'/../Fixtures/environmental-cracking-reference.json'),true,flags:JSON_THROW_ON_ERROR); }
    public function test_susceptibility_boundaries_curve_interpolation_and_operating_fractions_match_old(): void
    {
        $fixture=$this->fixture();$calculator=new EnvironmentalCrackingDamageFactor;
        foreach($fixture['cases'] as $case){
            $result=$calculator->calculate($case['input'],$case['kind'],$fixture['tables']);
            $this->assertTrue($result['ready'],json_encode([$case['kind'],$result['missing'],$case['input']],JSON_UNESCAPED_UNICODE));
            $this->compare($case['expected'],$result['res'],$case['kind']);
        }
    }
    public function test_unknown_alloy_weight_or_condition_is_not_replaced_by_the_first_row_or_100_percent(): void
    {
        $fixture=$this->fixture();$calculator=new EnvironmentalCrackingDamageFactor;
        $input=$fixture['cases'][0]['input'];$input['alloy']=null;
        $result=$calculator->calculate($input,'pascc',$fixture['tables']);$this->assertFalse($result['ready']);$this->assertContains('alloy',$result['missing']);$this->assertNull($result['res']['DF']);
        $input=$fixture['cases'][0]['input'];$input['flows'][0]['w']=null;
        $result=$calculator->calculate($input,'acscc',$fixture['tables']);$this->assertFalse($result['ready']);$this->assertContains('flows.flow-a.w',$result['missing']);
    }
    private function compare(mixed $expected,mixed $actual,string $path): void
    {
        if(is_array($expected)){foreach($expected as $key=>$value){if($key==='why')continue;$this->assertArrayHasKey($key,$actual,$path);$this->compare($value,$actual[$key],$path.'.'.$key);}}
        elseif(is_int($expected)||is_float($expected))$this->assertEqualsWithDelta($expected,$actual,max(1e-12,abs($expected)*1e-11),$path);
        else $this->assertSame($expected,$actual,$path);
    }
}
