<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\RbiDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RbiDateTest extends TestCase
{
    public function test_calendar_decimal_dates_match_the_independent_original_rbi_helper(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/old-rbi-dates.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(17, $fixture['cases']);
        foreach ($fixture['cases'] as $row) {
            $this->assertEqualsWithDelta($row['expected'], RbiDate::decimal($row['date']), 1e-12, $row['date']);
        }
        $this->assertSame(2024.0, RbiDate::decimal('2024-01-01'));
        $this->assertNotEquals(2024 + 59 / 366, RbiDate::decimal('2024-02-29'));
    }

    public static function invalidDates(): array
    {
        return array_map(fn ($date) => [$date], ['', '__prim', '2024', '2023-02-29', '2024-02-30', '2024-13-01', '0000-01-01', '2024-1-01', '2024-01-01T00:00:00Z']);
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_are_not_normalized_into_another_day(string $date): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RbiDate::decimal($date);
    }
}
