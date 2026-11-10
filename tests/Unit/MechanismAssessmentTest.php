<?php

namespace Tests\Unit;

use App\Modules\Equipment\Domain\Screening\ComponentScreeningSchema;
use App\Modules\Equipment\Domain\Screening\MechanismActivity;
use App\Modules\Equipment\Domain\Screening\MechanismAssessment;
use App\Modules\Equipment\Domain\Screening\MechanismContextFactory;
use PHPUnit\Framework\TestCase;

class MechanismAssessmentTest extends TestCase
{
    private function fixture(int $version = 1, bool $active = true): array
    {
        $components = [['id' => 'root', 'parent_id' => null, 'kind' => 'shell', 'type_code' => 'shell', 'is_coil' => false, 'material' => 'Grade', 'status' => 'active'],
            ['id' => 'first', 'parent_id' => 'root', 'kind' => 'element', 'type_code' => 'shell', 'is_coil' => false, 'material' => null, 'status' => $active ? 'active' : 'removed'],
            ['id' => 'second', 'parent_id' => 'root', 'kind' => 'element', 'type_code' => 'shell', 'is_coil' => false, 'material' => null, 'status' => 'active']];
        $physical = array_map(fn ($id) => ['component_id' => $id, 'current_element' => true, 'active' => $id === 'first' ? $active : true, 'insulated' => false, 'insulation_source' => 'manual', 'insulation_requires_review' => false], ['first', 'second']);
        $flows = ['contacts' => [['id' => 'inside', 'flows' => [['flow_id' => 'flow', 'accepted_version' => 1]]]], 'effective' => array_map(fn ($id) => ['component_id' => $id, 'side' => 'internal', 'contact_id' => 'inside', 'mode' => 'assign'], ['first', 'second'])];
        $cards = ['flow' => ['id' => 'flow', 'category' => 'main', 'medium_kind' => 'fluid', 'version' => $version, 'status' => 'active', 'screening' => ['values' => ['hcl' => true, 'water' => true, 'ph' => 1]]]];
        $factory = new MechanismContextFactory($components, ['temperature_max_c' => 20, 'pressure_operating_mpa' => 1], [], $flows, $cards, ['Grade' => ['id' => 'grade', 'version' => 1, 'status' => 'active', 'values' => ['subtype' => 'углеродистая', 'kind' => 'сталь', 'rbi_group' => 'углеродистая']]], [], [], ['elements' => $physical]);

        return [$factory, $physical];
    }

    private function facts(): array
    {
        return ['inputs' => ['vibration_notes' => null, 'hydrogen_notes' => null], 'overrides' => [], 'question_notes' => [], 'reviews' => []];
    }

    public function test_reviews_bind_current_inputs_and_stale_overrides_never_remain_effective(): void
    {
        [$factory,$physical] = $this->fixture();
        $assessment = new MechanismAssessment;
        $facts = $this->facts();
        $initial = $assessment->evaluate($factory, $physical, $facts, false);
        $first = $initial['elements'][0];
        $facts['reviews'] = [['component_id' => 'first', 'input_signature' => $first['input_signature'], 'verified_by' => 7, 'verified_at' => '2026-11-10T00:00:00Z']];
        $verified = $assessment->evaluate($factory, $physical, $facts, false);
        $this->assertTrue($verified['elements'][0]['review']['verified']);
        $facts['overrides'] = [['component_id' => 'first', 'mechanism_code' => 'hcl', 'side' => 'internal', 'state' => 'inactive', 'reason' => 'Уточнённый критерий', 'source_signature' => $first['source_signature']]];
        $changed = $assessment->evaluate($factory, $physical, $facts, false);
        $this->assertFalse($changed['overrides'][0]['stale']);
        $this->assertTrue($changed['elements'][0]['review']['outdated']);
        $this->assertSame('inactive', array_column($changed['elements'][0]['mechanisms'], null, 'id')['hcl']['state']);
        $this->assertSame('active', array_column($changed['elements'][1]['mechanisms'], null, 'id')['hcl']['state']);
        [$newFactory,$physical] = $this->fixture(2);
        $stale = $assessment->evaluate($newFactory, $physical, $facts, false);
        $this->assertTrue($stale['overrides'][0]['stale']);
        $this->assertSame('potential', array_column($stale['elements'][0]['mechanisms'], null, 'id')['hcl']['state']);
        $this->assertFalse($stale['elements'][0]['review']['verified']);
        $this->assertSame(7, $stale['elements'][0]['review']['verified_by']);
    }

    public function test_question_comments_change_review_signature_without_changing_calculation_inputs(): void
    {
        [$factory,$physical] = $this->fixture();
        $assessment = new MechanismAssessment;
        $facts = $this->facts();
        $initial = $assessment->evaluate($factory, $physical, $facts, false)['elements'][0];
        $facts['question_notes'] = [['component_id' => 'first', 'question_id' => 'hcl|1', 'side' => 'internal', 'flow_id' => 'flow', 'note' => 'Уточнён источник']];
        $changed = $assessment->evaluate($factory, $physical, $facts, false)['elements'][0];
        $this->assertSame($initial['source_signature'], $changed['source_signature']);
        $this->assertSame($initial['calculation_signature'], $changed['calculation_signature']);
        $this->assertNotSame($initial['input_signature'], $changed['input_signature']);
        $this->assertSame([], $changed['mechanisms'][0]['sides'][0]['runs']);
    }

    public function test_inactive_components_keep_saved_overrides_without_using_them_in_current_counts(): void
    {
        [$factory,$physical] = $this->fixture(1, false);
        $assessment = new MechanismAssessment;
        $facts = $this->facts();
        $facts['overrides'] = [['component_id' => 'first', 'mechanism_code' => 'hcl', 'side' => 'internal', 'state' => 'active', 'reason' => 'Прежняя детализация', 'source_signature' => str_repeat('a', 64)]];
        $result = $assessment->evaluate($factory, $physical, $facts, false);
        $this->assertCount(1, $result['overrides']);
        $this->assertTrue($result['overrides'][0]['stale']);
        $this->assertSame([], $result['elements'][0]['mechanisms']);
        $this->assertSame(array_fill_keys(['thin', 'ext', 'cui', 'cl', 'scc', 'hic', 'aus'], false), $result['activity']['first']);
    }

    public function test_thinning_uses_internal_activity_and_flange_exclusion_is_explicit(): void
    {
        $methods = [];
        foreach (ComponentScreeningSchema::metadata()['mechanisms'] as $method) {
            $methods[] = ['id' => $method['id'], 'group' => $method['group'], 'component_only' => $method['component_only'], 'sides' => array_map(fn ($side) => ['side' => $side, 'state' => 'inactive'], $method['side_policy'] === 'process' ? ['internal', 'external'] : [$method['side_policy']])];
        }
        $byId = array_column($methods, null, 'id');
        $byId['hcl']['sides'][1]['state'] = 'active';
        $rules = new MechanismActivity;
        $this->assertFalse($rules->categories(['mechanisms' => array_values($byId)], false, false)['thin']);
        $byId['hcl']['sides'][0]['state'] = 'active';
        $this->assertTrue($rules->categories(['mechanisms' => array_values($byId)], false, false)['thin']);
        $this->assertFalse($rules->categories(['mechanisms' => array_values($byId)], false, true)['thin']);
        $byId['hcl']['sides'][0]['state'] = 'potential';
        $this->assertNull($rules->categories(['mechanisms' => array_values($byId)],false,false)['thin']);
    }
}
