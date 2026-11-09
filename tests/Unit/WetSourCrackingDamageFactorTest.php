<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\WetSourCrackingDamageFactor;
use PHPUnit\Framework\TestCase;

class WetSourCrackingDamageFactorTest extends TestCase
{
    public function test_environment_material_boundaries_monitoring_inspections_and_operating_fractions_match_old(): void
    {
        $fixture=json_decode(file_get_contents(__DIR__.'/../Fixtures/wet-sour-reference.json'),true,flags:JSON_THROW_ON_ERROR);
        $calculator=new WetSourCrackingDamageFactor;
        foreach ($fixture['cases'] as $case) {
            $result=$calculator->calculate($case['input'],$case['kind'],$fixture['tables']);
            $this->assertTrue($result['ready'],json_encode([$case['kind'],$result['missing'],$case['input']],JSON_UNESCAPED_UNICODE));
            $this->compare($case['expected'],$result['res'],$case['kind']);
        }
    }

    private function compare(mixed $expected,mixed $actual,string $path): void
    {
        if (is_array($expected)) { foreach ($expected as $key=>$value) { $this->assertArrayHasKey($key,$actual,$path); $this->compare($value,$actual[$key],$path.'.'.$key); } }
        elseif (is_int($expected)||is_float($expected)) $this->assertEqualsWithDelta($expected,$actual,max(1e-12,abs($expected)*1e-11),$path);
        else $this->assertSame($expected,$actual,$path);
    }
}
