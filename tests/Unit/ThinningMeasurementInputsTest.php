<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\RbiDate;
use App\Modules\Rbi\Domain\ThinningMeasurementInputs;
use PHPUnit\Framework\TestCase;

class ThinningMeasurementInputsTest extends TestCase
{
    private function row(string $date, ?float $minimum): array
    {
        return ['stage_id' => $date, 'date' => $date, 'values' => ['min' => $minimum, 'ave' => 99], 'rates' => ['min' => ['nominal' => 9999]], 'complete' => true];
    }

    private function element(array $series): array
    {
        return ['nominal_mm' => 10, 'rejection_mm' => 4, 'rejection_source' => 'epb', 'series' => $series];
    }

    public function test_df_uses_element_min_and_worst_of_three_rates_without_reusing_different_corrosion_time(): void
    {
        $result = (new ThinningMeasurementInputs)->resolve($this->element([$this->row('2014-01-01', 8), $this->row('2012-01-01', 9)]), '2010-01-01', '2015-01-01', 0.1);
        $this->assertSame(0.5, $result['values']['CrM']);
        $this->assertSame(8.0, $result['values']['trdiM']);
        $this->assertSame(2014.0, $result['values']['tLast']);
        $this->assertSame(['nominal' => 0.5, 'first' => 0.5, 'short' => 0.5], $result['source']['rates']);
        $this->assertSame('epb', $result['source']['rejection_source']);
        $this->assertTrue($result['complete']);
        $leap = (new ThinningMeasurementInputs)->resolve($this->element([$this->row('2024-02-29', 9)]), '2020-07-01', '2025-01-01', null);
        $this->assertEqualsWithDelta(1 / (RbiDate::decimal('2024-02-29') - RbiDate::decimal('2020-07-01')), $leap['values']['CrM'], 1e-12);
    }

    public function test_project_zero_negative_measured_rate_and_zero_measurement_have_different_meanings(): void
    {
        $builder = new ThinningMeasurementInputs;
        $project = $builder->resolve($this->element([]), '2010-01-01', '2015-01-01', 0);
        $this->assertSame(0, $project['values']['CrT']);
        $this->assertNull($project['values']['CrM']);
        $this->assertTrue($project['complete']);
        $growth = $builder->resolve($this->element([$this->row('2014-01-01', 11)]), '2010-01-01', '2015-01-01', null);
        $this->assertSame(-0.25, $growth['values']['CrM']);
        $this->assertTrue($growth['complete']);
        $zero = $builder->resolve($this->element([$this->row('2014-01-01', 0)]), '2010-01-01', '2015-01-01', null);
        $this->assertSame(0.0, $zero['values']['trdiM']);
        $this->assertContains('trdiM', $zero['invalid']);
        $this->assertFalse($zero['complete']);
        $unknown = $builder->resolve($this->element([]), null, '2015-01-01', null);
        $this->assertContains('tIn', $unknown['missing']);
        $this->assertContains('corrosion_rate', $unknown['missing']);
    }

    public function test_unknown_and_future_measurements_do_not_fill_the_past_and_primary_before_commissioning_remains_evidence(): void
    {
        $builder = new ThinningMeasurementInputs;
        $future = $this->row('2030-01-01', 1);
        $result = $builder->resolve($this->element([$this->row('2011-01-01', null), $this->row('2012-01-01', 9), $future]), '2010-01-01', '2015-01-01', null);
        $this->assertSame(1, $result['source']['measurement_count']);
        $this->assertSame(2012.0, $result['values']['tLast']);
        $this->assertSame('measurement_after_analysis', $result['warnings'][0]['code']);
        $initial = $builder->resolve($this->element([$this->row('2009-12-31', 10)]), '2010-01-01', '2015-01-01', 0.1);
        $this->assertTrue($initial['complete']);
        $this->assertSame('measured_rate_unavailable', $initial['warnings'][0]['code']);
        $this->assertSame('project', $initial['source']['rate_source']);
        $sameDay = $builder->resolve($this->element([$this->row('2014-01-01', 8), $this->row('2014-01-01', 8)]), '2010-01-01', '2015-01-01', null);
        $this->assertNull($sameDay['source']['rates']['first']);
        $this->assertNull($sameDay['source']['rates']['short']);
        $this->assertSame(0.5, $sameDay['values']['CrM']);
        $conflicting = $builder->resolve($this->element([$this->row('2014-01-01', 8), $this->row('2014-01-01', 7)]), '2010-01-01', '2015-01-01', null);
        $this->assertContains('conflicting_measurements_same_day', $conflicting['invalid']);
        $this->assertFalse($conflicting['complete']);
        $this->assertSame(['2014-01-01'], $conflicting['source']['conflicting_measurement_dates']);
    }
}
