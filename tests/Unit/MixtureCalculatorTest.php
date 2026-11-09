<?php

namespace Tests\Unit;

use App\Modules\ProcessFlows\Domain\MixtureCalculator;
use PHPUnit\Framework\TestCase;

class MixtureCalculatorTest extends TestCase
{
    private function rows(): array
    {
        return [
            ['id' => 'a', 'role' => 'main', 'amount' => 20, 'substance_id' => 'A', 'substance_version' => 1, 'substance_values' => ['name' => 'A', 'mw' => 10, 'nbp' => 0, 'dens' => 1000, 'flammable' => true, 'toxic' => true, 'nfnt' => false, 'inert' => false]],
            ['id' => 'b', 'role' => 'main', 'amount' => 80, 'substance_id' => 'B', 'substance_version' => 1, 'substance_values' => ['name' => 'B', 'mw' => 20, 'nbp' => 100, 'dens' => 2000, 'flammable' => false, 'toxic' => null, 'nfnt' => true, 'inert' => false]],
        ];
    }

    public function test_mass_mole_conversion_multi_criteria_and_input_order_signature(): void
    {
        $engine = new MixtureCalculator;
        $rows = $this->rows();
        $result = $engine->calculate('mass', $rows);
        $this->assertTrue($result['complete']);
        $this->assertEqualsWithDelta(100 / 6, $result['properties']['molecular_weight'], 1e-10);
        $this->assertEqualsWithDelta(100 / 3, $result['rows'][0]['mole_percent'], 1e-10);
        $this->assertEqualsWithDelta(200 / 3, $result['properties']['boiling_point'], 1e-10);
        $this->assertEqualsWithDelta(5000 / 3, $result['properties']['liquid_density'], 1e-8);
        $criteria = array_column($result['criteria'], null, 'criterion');
        $this->assertEquals(20, $criteria['flammable']['mass_percent']);
        $this->assertEquals(20, $criteria['toxic']['mass_percent']);
        $this->assertEquals(80, $criteria['unknown']['mass_percent']);
        $this->assertSame(false, $result['rows'][1]['flags']['flammable']);
        $this->assertNull($result['rows'][1]['flags']['toxic']);
        $this->assertSame($result['signature'], $engine->calculate('mass', array_reverse($rows))['signature']);
        $rows[0]['notes'] = 'Комментарий не является входом расчёта';
        $rows[0]['position'] = 10;
        $this->assertSame($result['signature'], $engine->calculate('mass', $rows)['signature']);
    }

    public function test_missing_mw_qualified_properties_incomplete_sums_and_overflow_never_generate_values(): void
    {
        $engine = new MixtureCalculator;
        $rows = $this->rows();
        $rows[0]['substance_values']['mw'] = null;
        $result = $engine->calculate('mole', $rows);
        $this->assertFalse($result['complete']);
        $this->assertNull($result['rows'][0]['mass_percent']);
        $this->assertNull($result['properties']['molecular_weight']);
        $rows = $this->rows();
        $rows[0]['substance_values']['nbp'] = 'разлагается';
        $this->assertNull($engine->calculate('mass', $rows)['properties']['boiling_point']);
        $rows[0]['amount'] = null;
        $this->assertFalse($engine->calculate('mass', $rows)['complete']);
        $rows = $this->rows();
        $rows[0]['substance_values']['mw'] = 1e-320;
        $result = $engine->calculate('mass', $rows);
        $this->assertFalse($result['complete']);
        $this->assertNotEmpty(json_encode($result, JSON_THROW_ON_ERROR));
        $empty = $engine->calculate('mass', []);
        $this->assertFalse($empty['complete']);
        $this->assertNull($empty['properties']['liquid_density']);
        $rows = $this->rows();
        foreach ($rows as &$row) {
            $row['substance_values']['mw'] = 1e305;
            $row['substance_values']['nbp'] = 1e307;
        }
        unset($row);
        $large = $engine->calculate('mole', $rows);
        $this->assertEqualsWithDelta(20, $large['rows'][0]['mass_percent'], 1e-10);
        $this->assertNotEmpty(json_encode($large, JSON_THROW_ON_ERROR));
    }
}
