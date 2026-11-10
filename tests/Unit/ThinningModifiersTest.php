<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\ThinningModifiers;
use App\Modules\Rbi\Infrastructure\InputReferenceTables;
use PHPUnit\Framework\TestCase;

class ThinningModifiersTest extends TestCase
{
    public function test_monitoring_uses_the_lowest_factor_and_explicit_zero_velocity_is_not_unknown(): void
    {
        $tables = (new InputReferenceTables)->snapshot()['tables'];
        $builder = new ThinningModifiers;
        $low = $builder->monitoring(['h2so4'], 'kp', 0, ['subtype' => 'углеродистая'], $tables);
        $high = $builder->monitoring(['h2so4'], 'kp', null, ['subtype' => 'углеродистая'], $tables);
        $this->assertSame(20, $low['value']);
        $this->assertSame('low', $low['source']['rows'][0]['velocity_class']);
        $this->assertSame(10, $high['value']);
        $this->assertTrue($high['source']['rows'][0]['velocity_unknown']);
        $this->assertSame(1, $builder->monitoring(['hcl', 'hth2s'], 'kp', 3, null, $tables)['value']);
        $this->assertSame(10, $builder->monitoring(['hth2s'], 'kpc', 3, null, $tables)['value']);
        $this->assertNull($builder->monitoring(['hcl'], null, null, null, $tables)['value']);
        $this->assertSame(1, $builder->monitoring(['hcl'], 'отсутствует', null, null, $tables)['value']);
        $this->assertSame(20, $builder->monitoring(['sw'], 'kp', 6.1, null, $tables)['value']);
        $this->assertSame(10, $builder->monitoring(['sw'], 'kp', 6.1001, null, $tables)['value']);
    }

    public function test_geometry_has_explicit_coefficient_provenance_and_no_default_weld_efficiency_for_unknown_seam(): void
    {
        $tables = (new InputReferenceTables)->snapshot()['tables'];
        $builder = new ThinningModifiers;
        $this->assertSame(['alpha' => 2, 'E' => null], $builder->geometry('pipe', false, null, null, $tables)['values']);
        $this->assertSame(['alpha' => 1.13, 'E' => 0.85], $builder->geometry('flanged_cap', false, 'welded', 0.85, $tables)['values']);
        $this->assertSame(['alpha' => 4, 'E' => 1], $builder->geometry('head', true, 'seamless', null, $tables)['values']);
        $this->assertSame(['alpha', 'E'], $builder->geometry(null, false, null, null, $tables)['missing']);
    }

    public function test_pipe_localization_unknown_and_contradiction_do_not_silently_become_factor_one(): void
    {
        $builder = new ThinningModifiers;
        $this->assertSame(['FIP' => 1, 'FDL' => 1], $builder->pipeFactors(false, null, null, null, null)['values']);
        $this->assertSame(['FIP' => 3, 'FDL' => 1], $builder->pipeFactors(true, true, false, true, null)['values']);
        $this->assertSame(['FIP'], $builder->pipeFactors(true, true, false, null, null)['missing']);
        $this->assertSame(['FIP_contradiction'], $builder->pipeFactors(true, false, false, true, null)['invalid']);
    }
}
