<?php

namespace Tests\Unit;

use App\Domain\CorrosionCalculator;
use PHPUnit\Framework\TestCase;

class MeasurementAnomaliesTest extends TestCase
{
    private function calculate(array $measurements, bool $late = false): array
    {
        $components = [['id' => 'shell', 'parent_id' => null, 'code' => 'K-01', 'name' => 'Сосуд', 'is_coil' => false, 'status' => 'active', 'nominal_thickness_mm' => 10, 'rejection_passport_mm' => 4]];
        $cmls = [['id' => 'cml', 'component_id' => 'shell', 'point_count' => count($measurements[0])]];
        $stages = [];
        $inspections = [];
        foreach ($measurements as $index => $points) {
            $date = (2020 + $index).'-01-01';
            $stages[] = ['id' => 'stage'.$index, 'inspection_id' => 'inspection'.$index, 'measured_on' => $date, 'measurements' => [['cml_id' => 'cml', 'points' => $points]]];
            $inspections[] = ['id' => 'inspection'.$index, 'performed_on' => $late ? '2019-12-31' : $date];
        }

        return (new CorrosionCalculator)->calculate(['commissioned_on' => '2000-01-01'], $components, ['cmls' => $cmls, 'stages' => $stages], $inspections)['anomalies'];
    }

    public function test_all_applicable_element_signals_are_retained(): void
    {
        $anomalies = $this->calculate([[9, 9.2, 9.4], [9.2, 9.4, 9.6], [11, 11.2, 11.4]]);
        $types = array_column($anomalies, 'type');
        $this->assertContains('probable_copy', $types);
        $this->assertContains('probable_instrument_setting', $types);
        $this->assertContains('probable_element_replacement', $types);
        $this->assertGreaterThanOrEqual(3, count(array_filter($anomalies, fn ($row) => $row['stage_id'] === 'stage2')));
    }

    public function test_point_outliers_date_order_and_ordinary_spread(): void
    {
        $anomalies = $this->calculate([[0, 9.2, 9.4, 22]], true);
        $types = array_column($anomalies, 'type');
        $this->assertContains('additional_control', $types);
        $this->assertContains('probable_recording_error', $types);
        $this->assertContains('measurement_after_inspection', $types);
        $point = array_values(array_filter($anomalies, fn ($row) => $row['type'] === 'additional_control'))[0];
        $this->assertSame(1, $point['point']);
        $this->assertSame('cml', $point['cml_id']);
        $this->assertSame([], $this->calculate([[9.8, 9.9, 10], [9.9, 9.8, 10.05]]));
    }

    public function test_sudden_thinning_uses_average_element_rates(): void
    {
        $anomalies = $this->calculate([[9.8, 9.7, 9.9], [8, 8.1, 8.2]]);
        $this->assertContains('sudden_thinning', array_column($anomalies, 'type'));
        $this->assertNotContains('probable_instrument_setting', array_column($anomalies, 'type'));
    }
}
