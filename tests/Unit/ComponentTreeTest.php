<?php

namespace Tests\Unit;

use App\Modules\Equipment\Domain\ComponentTree;
use App\Modules\ProcessFlows\Application\EquipmentFlowContacts;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ComponentTreeTest extends TestCase
{
    private function rows(): array
    {
        return [
            ['id' => 'root', 'parent_id' => null, 'kind' => 'shell', 'is_coil' => false, 'status' => 'active', 'nominal_thickness_mm' => 12],
            ['id' => 'assembly', 'parent_id' => 'root', 'kind' => 'element', 'is_coil' => false, 'status' => 'active', 'flow_space' => 'tube', 'nominal_thickness_mm' => 10],
            ['id' => 'leaf', 'parent_id' => 'assembly', 'kind' => 'element', 'is_coil' => false, 'status' => 'active'],
        ];
    }

    public function test_ancestry_and_flow_inheritance_follow_every_assembly_level(): void
    {
        $rows = $this->rows();
        $tree = new ComponentTree($rows);
        $this->assertSame(['leaf', 'assembly', 'root'], array_column($tree->lineage('leaf'), 'id'));
        $this->assertSame(10, $tree->value('leaf', 'nominal_thickness_mm'));
        $this->assertSame('tube', $tree->value('leaf', 'flow_space'));
        $contacts = [['id' => 'parent-assignment', 'scope' => 'component', 'component_id' => 'assembly', 'side' => 'internal', 'mode' => 'none', 'flows' => []]];
        $effective = (new EquipmentFlowContacts)->effective($rows, $contacts);
        $this->assertSame('parent-assignment', $effective[8]['contact_id']);
        $this->assertTrue($effective[8]['inherited']);
        $rows[1]['status'] = 'removed';
        $this->assertFalse((new ComponentTree($rows))->active('leaf'));
        $this->assertFalse((new EquipmentFlowContacts)->effective($rows, $contacts)[8]['active']);
    }

    public function test_cycles_are_rejected(): void
    {
        $rows = $this->rows();
        $rows[0]['parent_id'] = 'leaf';
        $this->expectException(InvalidArgumentException::class);
        (new ComponentTree($rows))->lineage('leaf');
    }

    public function test_missing_parent_is_not_treated_as_a_root(): void
    {
        $rows = $this->rows();
        $rows[1]['parent_id'] = 'foreign';
        $this->expectException(InvalidArgumentException::class);
        (new ComponentTree($rows))->root('leaf');
    }
}
