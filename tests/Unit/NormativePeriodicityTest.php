<?php

namespace Tests\Unit;

use App\Modules\Diagnostics\Domain\NormativePeriodicity;
use PHPUnit\Framework\TestCase;

class NormativePeriodicityTest extends TestCase
{
    public function test_boundaries_registration_and_missing_inputs(): void
    {
        $calculator = new NormativePeriodicity;
        foreach ([['ФНП ОРПД', 'РТН', 0.1, 2, 4, 8], ['ФНП ОРПД', 'РТН', 0.100001, 1, 4, 8],
            ['ФНП ОРПД', 'ОТН', 0.1, 2, null, 8], ['ФНП ОРПД', 'ОТН', 0.2, 1, null, 8],
            ['ИТНЭ-93', 'ОТН', 0.1, 6, null, 12], ['ИТНЭ-93', 'ОТН', 0.10001, 2, null, 8], ['ИТНЭ-93', 'ОТН', 0.3, 2, null, 8], ['ИТНЭ-93', 'ОТН', 0.30001, 1, null, 8],
            ['РУА-93', 'ОТН', 0.1, 6, null, 12], ['РУА-93', 'ОТН', 0.2, 2, null, 8], ['РУА-93', 'ОТН', 0.4, 1, null, 8]] as [$ntd, $registration, $speed, $otn, $rtn, $hydro]) {
            $result = $calculator->calculate($ntd, $registration, $speed, null, null);
            $this->assertSame([$otn, $rtn, $hydro], [$result['otn_years'], $result['rtn_years'], $result['hydro_years']]);
            $this->assertSame($speed > 0.1, $result['frequency_increased']);
        }
        $this->assertSame('определите скорость коррозии', $calculator->calculate('ФНП ОРПД', 'РТН', null, null, null)['message']);
        $this->assertFalse($calculator->calculate('Другой НТД', 'РТН', 1, 1, 1)['applicable']);
        $this->assertFalse($calculator->calculate('ФНП ОРПД', 'РТН', 1, 1, 1, false)['applicable']);
        $this->assertSame(0.5, $calculator->calculate('ФНП ОРПД', 'РТН', 0.1, 0.2, 0.5)['accepted_speed']);
        $this->assertNull($calculator->calculate('ИТНЭ-93', 'РТН', 0.1, null, null)['otn_years']);
    }
}
