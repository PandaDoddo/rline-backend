<?php

namespace Tests\Unit;

use App\Modules\ProcessFlows\Application\EquipmentFlowContacts;
use PHPUnit\Framework\TestCase;

class EquipmentFlowProjectionTest extends TestCase
{
    public function test_large_inherited_matrix_references_contacts_without_duplicating_flow_metadata(): void
    {
        $components = [];
        for ($index = 0; $index < 500; $index++) {
            $components[] = ['id' => 'component-'.$index, 'parent_id' => null, 'kind' => 'shell', 'is_coil' => false, 'status' => 'active'];
        }
        $links = [];
        for ($index = 0; $index < 100; $index++) {
            $links[] = ['id' => 'link-'.$index, 'flow_id' => 'flow-'.$index, 'flow_name' => str_repeat('Flow ', 10).$index, 'flow_category' => 'main', 'flow_status' => 'active', 'flow_version' => 1, 'accepted_version' => 1, 'stale' => false, 'share_percent' => null, 'notes' => null];
        }
        $contacts = [];
        foreach (['internal', 'external', 'heating', 'insulation'] as $side) {
            $contacts[] = ['id' => 'contact-'.$side, 'scope' => 'equipment', 'component_id' => null, 'side' => $side, 'mode' => 'assign', 'flows' => $links];
        }
        $effective = (new EquipmentFlowContacts)->effective($components, $contacts);
        $this->assertCount(2000, $effective);
        $this->assertSame('contact-internal', $effective[0]['contact_id']);
        $this->assertArrayNotHasKey('flows', $effective[0]);
        $compact = strlen(json_encode(['contacts' => $contacts, 'effective' => $effective], JSON_THROW_ON_ERROR));
        $legacy = $effective;
        foreach ($legacy as &$row) {
            $row['flows'] = $links;
        }
        unset($row);
        $expanded = strlen(json_encode(['contacts' => $contacts, 'effective' => $legacy], JSON_THROW_ON_ERROR));
        $this->assertLessThan($expanded / 50, $compact);
        $this->assertLessThan(600000, $compact);
    }
}
