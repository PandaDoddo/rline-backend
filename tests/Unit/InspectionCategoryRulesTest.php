<?php

namespace Tests\Unit;

use App\Modules\Diagnostics\Domain\InspectionCategoryRules;
use PHPUnit\Framework\TestCase;

class InspectionCategoryRulesTest extends TestCase
{
    public function test_thinning_matches_independent_old_oracle_for_measurement_coverage_and_inspection_types(): void
    {
        $reference = json_decode(file_get_contents(__DIR__.'/../Fixtures/old-inspection-categories.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(26, $reference['source']['checksums']);
        $this->assertCount(16, $reference['cases']);
        $rules = new InspectionCategoryRules;
        foreach ($reference['cases'] as $case) {
            $this->assertSame($case['expected'], $rules->thinning($case['pipeline'], $case['internal'], $case['total'], $case['measured']), json_encode($case));
        }
    }

    public function test_visual_distinguishes_unknown_from_absence_and_partial_inspection(): void
    {
        $rules = new InspectionCategoryRules;
        $this->assertSame('E', $rules->visual([], false));
        $this->assertSame('E', $rules->visual(['notes' => 'Нужно уточнить', 'area_unit' => 'percent'], false));
        $this->assertSame('B', $rules->visual(['corrosion_present' => false], false));
        $this->assertSame('B', $rules->visual([], true));
        $this->assertSame('C', $rules->visual(['partial' => true], false));
        $this->expectException(\InvalidArgumentException::class);
        $rules->thinning(false,true,1,2);
    }
}
