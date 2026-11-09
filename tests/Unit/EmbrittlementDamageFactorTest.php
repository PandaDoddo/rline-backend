<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\EmbrittlementDamageFactor;
use PHPUnit\Framework\TestCase;

class EmbrittlementDamageFactorTest extends TestCase
{
    private function fixture(): array{return json_decode(file_get_contents(__DIR__.'/../Fixtures/embrittlement-reference.json'),true,flags:JSON_THROW_ON_ERROR);}
    public function test_material_curves_bilinear_tables_cet_fse_and_embrittlement_boundaries_match_old(): void
    {
        $fixture=$this->fixture();$calculator=new EmbrittlementDamageFactor;
        foreach($fixture['cases']as$case){$result=$calculator->calculate($case['input'],$case['kind'],$fixture['tables']);$this->assertTrue($result['ready'],json_encode([$case['kind'],$result['missing'],$case['input']],JSON_UNESCAPED_UNICODE));$this->compare($case['expected'],$result['res'],$case['kind']);}
    }
    public function test_missing_fse_cannot_claim_a_hundredfold_reduction_and_empty_temperature_stays_missing(): void
    {
        $fixture=$this->fixture();$input=$fixture['cases'][0]['input'];$input['fse']=[];
        $calculator=new EmbrittlementDamageFactor;$result=$calculator->calculate($input,'bf',$fixture['tables']);$this->assertFalse($result['ready']);$this->assertContains('fse',$result['missing']);$this->assertNull($result['res']['DF']);
        foreach(['paz','mdmt','upset','tmin']as$key)$input[$key]=null;
        $result=$calculator->calculate($input,'e885',$fixture['tables']);$this->assertFalse($result['ready']);$this->assertNull($result['res']['DF']);
    }
    private function compare(mixed $expected,mixed $actual,string $path): void
    {
        if(is_array($expected)){foreach($expected as$key=>$value){if(in_array($key,['artV','srpV','dfbT'],true))continue;$this->assertArrayHasKey($key,$actual,$path);$this->compare($value,$actual[$key],$path.'.'.$key);}}
        elseif(is_int($expected)||is_float($expected))$this->assertEqualsWithDelta($expected,$actual,max(1e-12,abs($expected)*1e-11),$path);
        else$this->assertSame($expected,$actual,$path);
    }
}
