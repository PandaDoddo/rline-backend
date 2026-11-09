<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\RiskTimeline;
use PHPUnit\Framework\TestCase;

class RiskTimelineTest extends TestCase
{
    private function tables(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../resources/rbi/reference-tables-v1.json'), true, flags: JSON_THROW_ON_ERROR)['tables'];
    }

    private function cases(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/risk-timeline-reference.json'), true, flags: JSON_THROW_ON_ERROR)['cases'];
    }

    public function test_forecasts_threshold_dates_and_hypothetical_inspections_match_old(): void
    {
        foreach ($this->cases() as $index => $case) {
            $input = $case['input'];
            $result = (new RiskTimeline)->calculate($input, $case['targets'], $this->tables());
            $this->assertTrue($result['ready'], json_encode([$index, $result['errors'] ?? null]));
            // Legacy JS &&/|| leak null into these boolean flags when no thickness model exists.
            foreach (['thkNow', 'thkBad', 'extra'] as $flag) {
                $case['expected'][$flag] = (bool) $case['expected'][$flag];
            }
            $this->compare($case['expected'], $result['res'], 'case.'.$index);
            $this->assertSame($case['input'], $input, 'Scenario must not mutate source inputs.');
        }
    }

    public function test_missing_consequences_and_unsupported_active_mechanisms_never_become_zero_risk(): void
    {
        $case = $this->cases()[0];
        unset($case['input']['CA']);
        $result = (new RiskTimeline)->calculate($case['input'], $case['targets'], $this->tables());
        $this->assertFalse($result['ready']);
        $this->assertContains('CA', $result['missing']);
        $this->assertNull($result['res']['before']['ra']);
        $case['input']['kinds']['mfat'] = [];
        $result = (new RiskTimeline)->calculate($case['input'], $case['targets'], $this->tables());
        $this->assertFalse($result['ready']);
        $this->assertNull($result['res']);
        $this->assertSame('mfat', $result['errors'][0]['mechanism']);
    }

    private function compare(mixed $expected, mixed $actual, string $path): void
    {
        if (is_array($expected)) {
            $this->assertSame(array_keys($expected), array_keys($actual), $path);
            foreach ($expected as $key => $value) {
                $this->compare($value, $actual[$key], $path.'.'.$key);
            }
        } elseif (is_int($expected) || is_float($expected)) {
            $this->assertEqualsWithDelta($expected, $actual, max(1e-10, abs($expected) * 1e-11), $path);
        } else {
            $this->assertSame($expected,$actual,$path);
        }
    }
}
