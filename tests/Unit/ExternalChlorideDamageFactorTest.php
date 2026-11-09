<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\ExternalChlorideDamageFactor;
use PHPUnit\Framework\TestCase;

class ExternalChlorideDamageFactorTest extends TestCase
{
    public function test_temperature_boundaries_inspection_equivalence_coatings_and_cui_corrections_match_old(): void
    {
        $cases=json_decode(file_get_contents(__DIR__.'/../Fixtures/external-chloride-reference.json'),true,flags:JSON_THROW_ON_ERROR)['cases'];
        $calculator=new ExternalChlorideDamageFactor;
        foreach ($cases as $case) {
            $result=$calculator->calculate($case['input'],$case['kind']); $this->assertTrue($result['ready']);
            foreach ($case['expected'] as $key=>$value) {
                if (is_array($value)) foreach ($value as $index=>$number) $this->assertEqualsWithDelta($number,$result['res'][$key][$index],max(1e-12,abs($number)*1e-11),$key);
                elseif (is_int($value)||is_float($value)) $this->assertEqualsWithDelta($value,$result['res'][$key],max(1e-12,abs($value)*1e-11),$key);
                else $this->assertSame($value,$result['res'][$key],$key);
            }
        }
    }
}
