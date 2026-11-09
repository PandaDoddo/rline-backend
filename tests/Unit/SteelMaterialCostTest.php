<?php

namespace Tests\Unit;

use App\Modules\Catalogs\Domain\SteelMaterialCost;
use PHPUnit\Framework\TestCase;

class SteelMaterialCostTest extends TestCase
{
    public function test_all_original_grade_results_match_the_previous_frontend_calculator(): void
    {
        $base = json_decode(file_get_contents(__DIR__.'/../../resources/catalogs/steel-grades.json'), true)['entries'];
        $expected = json_decode(file_get_contents(__DIR__.'/../Fixtures/steel-material-costs.json'), true)['entries'];
        $rows = json_decode(file_get_contents(__DIR__.'/../../resources/catalogs/material-costs.json'), true)['entries'];
        $rows = array_map(fn ($row) => ['id' => $row['english'], 'label' => $row['name'], 'english_name' => $row['english'], 'material_group' => $row['group'], 'base_values' => ['factor' => $row['factor']], 'overrides' => [], 'version' => 1, 'base_version' => 'test'], $rows);
        foreach ($base as $i => $entry) {
            $this->assertSame($entry['code'], $expected[$i]['code']);
            $this->assertEquals($expected[$i]['factor'], (new SteelMaterialCost)->describe($entry['values'], $rows)['factor'], $entry['values']['name']);
        }
    }

    public function test_all_mapping_boundaries_and_missing_composition_are_explicit(): void
    {
        // Boot Laravel's collection helpers; no database is used by this calculator.
        $rows = json_decode(file_get_contents(__DIR__.'/../../resources/catalogs/material-costs.json'), true)['entries'];
        $rows = array_map(fn ($row) => ['id' => $row['english'], 'label' => $row['name'], 'english_name' => $row['english'], 'material_group' => $row['group'], 'base_values' => ['factor' => $row['factor']], 'overrides' => [], 'version' => 1, 'base_version' => 'test'], $rows);
        $engine = new SteelMaterialCost;
        foreach ([[1.5, 1.3], [1.5001, 1.7], [3, 1.7], [3.001, 1.7], [6, 1.7], [6.01, 2], [8, 2], [8.01, 2.6]] as [$cr, $expected]) {
            $cost = $engine->describe(['subtype' => 'низколегированная', 'refine' => 'хроммолибденовая', 'composition' => [['el' => 'Cr', 'max' => $cr]]], $rows);
            $this->assertEquals($expected, $cost['factor']);
        }
        $this->assertSame(4.8, $engine->describe(['subtype' => 'аустенитная нержавеющая', 'composition' => [['el' => 'Mo', 'min' => 1.5]]], $rows)['factor']);
        $this->assertSame(1.3, $engine->describe(['subtype' => 'низколегированная', 'composition' => [['el' => 'Mo', 'max' => 0]]], $rows)['factor']);
        $missing = $engine->describe(['subtype' => 'аустенитная нержавеющая'], $rows);
        $this->assertTrue($missing['warning']);
        $this->assertCount(3, $missing['assumptions']);
        $this->assertNull($engine->describe(['rbi_matcost' => 999], $rows)['factor']);
        $this->assertTrue($engine->describe(['subtype' => 'дуплексная нержавеющая'], $rows)['warning']);
    }
}
