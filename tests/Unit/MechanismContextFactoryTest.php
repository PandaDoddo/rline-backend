<?php

namespace Tests\Unit;

use App\Modules\Equipment\Domain\Screening\MechanismContextFactory;
use PHPUnit\Framework\TestCase;

class MechanismContextFactoryTest extends TestCase
{
    private function factory(array $settings = [], array $contacts = [], array $cards = []): MechanismContextFactory
    {
        $components = [['id' => 'body', 'parent_id' => null, 'kind' => 'shell', 'type_code' => 'shell', 'is_coil' => false, 'material' => '20', 'status' => 'active'],
            ['id' => 'first', 'parent_id' => 'body', 'kind' => 'element', 'type_code' => 'shell', 'is_coil' => false, 'material' => null, 'status' => 'active'],
            ['id' => 'second', 'parent_id' => 'body', 'kind' => 'element', 'type_code' => 'shell', 'is_coil' => false, 'material' => null, 'status' => 'active'],
            ['id' => 'coil', 'parent_id' => null, 'kind' => 'coil', 'type_code' => 'coil', 'is_coil' => true, 'material' => '20', 'status' => 'active'],
            ['id' => 'tubeHead', 'parent_id' => null, 'kind' => 'head', 'type_code' => 'head', 'flow_space' => 'tube', 'is_coil' => false, 'material' => '20', 'status' => 'active']];
        $effective = [];
        foreach (['first', 'second', 'coil'] as $id) {
            foreach (['internal', 'external', 'insulation'] as $side) {
                $effective[] = ['component_id' => $id, 'side' => $side, 'contact_id' => $id === 'coil' ? null : ($contacts[$side]['id'] ?? null), 'mode' => $id === 'coil' ? 'unknown' : ($contacts[$side]['mode'] ?? 'unknown')];
            }
        }
        $visual = ['elements' => array_map(fn ($id) => ['component_id' => $id, 'insulated' => null, 'insulation_source' => 'unknown', 'insulation_requires_review' => false], ['first', 'second', 'coil'])];

        return new MechanismContextFactory($components, ['temperature_max_c' => 0, 'pressure_operating_mpa' => 0, 'insulation_present' => 'отсутствует', 'ground_location' => 'надземное'],
            [['kind' => 'coil', 'component_id' => 'coil', 'values' => (object) ['temperature_max_c' => 100]], ['kind' => 'tube', 'component_id' => null, 'values' => (object) ['temperature_max_c' => 250, 'pressure_operating_mpa' => 0.5]]], ['contacts' => array_values($contacts), 'effective' => $effective, 'selected' => [['flow_id' => 'unassigned']]], $cards,
            ['20' => ['id' => 'grade', 'status' => 'active', 'version' => 1, 'values' => ['kind' => 'сталь', 'subtype' => 'углеродистая', 'matMin' => -20]]], ['vibration_present' => false], $settings, $visual);
    }

    public function test_scopes_are_independent_selected_streams_do_not_assign_exposure_and_zero_is_preserved(): void
    {
        $factory = $this->factory();
        $first = $factory->element('first', true);
        $second = $factory->element('second', true);
        $coil = $factory->element('coil', true);
        $tube = $factory->element('tubeHead', false);
        $this->assertSame(250.0, $tube['context']['parameters']['temperature_max_c']);
        $this->assertSame(0.5, $tube['context']['parameters']['pressure_operating_mpa']);
        $this->assertSame('tube:', $tube['context']['references']['scope']);
        $this->assertSame($first['key'], $second['key']);
        $this->assertSame([], $first['internal']);
        $this->assertSame(0.0, $first['context']['parameters']['temperature_max_c']);
        $this->assertSame(0.0, $first['context']['parameters']['pressure_operating_mpa']);
        $this->assertSame(-20.0, $first['context']['parameters']['temperature_mat_c']);
        $this->assertFalse($first['context']['insulated']);
        $this->assertFalse($first['context']['vibration_present']);
        $this->assertSame(100.0, $coil['context']['parameters']['temperature_max_c']);
        $this->assertNull($coil['context']['parameters']['pressure_operating_mpa']);
        $this->assertNull($coil['context']['insulated']);
        $this->assertNull($coil['context']['soil_contact']);
        $this->assertNotSame($first['key'], $coil['key']);
    }

    public function test_physical_assignment_versions_and_inherited_manual_inputs_are_part_of_the_context(): void
    {
        $cards = ['flow' => ['id' => 'flow', 'version' => 2, 'status' => 'active', 'category' => 'main', 'medium_kind' => 'fluid', 'screening' => null]];
        $contacts = ['internal' => ['id' => 'contact', 'mode' => 'assign', 'flows' => [['flow_id' => 'flow', 'accepted_version' => 1]]],
            'external' => ['id' => 'outside', 'mode' => 'assign', 'flows' => [['flow_id' => 'flow', 'accepted_version' => 1]]]];
        $factory = $this->factory(['body' => ['vibration_excluded' => true], 'second' => ['vibration_excluded' => false]], $contacts, $cards);
        $first = $factory->element('first', true);
        $second = $factory->element('second', true);
        $this->assertFalse($first['internal'][0]['usable']);
        $this->assertFalse($first['context']['external_source_usable']);
        $this->assertNull($first['context']['external_process']);
        $this->assertTrue($first['context']['vibration_excluded']);
        $this->assertFalse($second['context']['vibration_excluded']);
        $this->assertNotSame($first['key'], $second['key']);
        $contacts['internal']['flows'][0]['accepted_version'] = 2;
        $fresh = $this->factory([], $contacts, $cards)->element('first', true);
        $this->assertTrue($fresh['internal'][0]['usable']);
        $this->assertNotSame($fresh['key'], $first['key']);
    }
}
