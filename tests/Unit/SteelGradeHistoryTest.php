<?php

namespace Tests\Unit;

use App\Modules\Catalogs\Domain\SteelGradeHistory;
use PHPUnit\Framework\TestCase;

class SteelGradeHistoryTest extends TestCase
{
    public function test_changes_identify_composition_and_stress_cells_instead_of_only_row_counts(): void
    {
        $history = new SteelGradeHistory;
        $composition = $history->changes('composition', ['label' => 'Состав'], [['el' => 'C', 'min' => null, 'max' => 0.2]], [['el' => 'C', 'min' => 0, 'max' => 0.3]]);
        $this->assertCount(2, $composition);
        $this->assertSame('composition|C · min', $composition[0]['key']);
        $this->assertNull($composition[0]['from']);
        $this->assertSame('0', $composition[0]['to']);
        $before = ['columns' => [['id' => 'gost', 'label' => 'ГОСТ']], 'rows' => [['t' => 20, 'gost' => 100], ['t' => 50, 'gost' => null]]];
        $after = $before;
        $after['rows'][1]['gost'] = 0;
        $changes = $history->changes('stress', ['label' => 'Напряжения'], $before, $after);
        $this->assertCount(1, $changes);
        $this->assertSame('stress|50 °C · gost', $changes[0]['key']);
        $this->assertSame('0', $changes[0]['to']);
    }
}
