<?php

namespace Tests\Unit;

use App\Modules\Equipment\Domain\Screening\ComponentMechanismScreening;
use App\Modules\Rbi\Domain\DamageFactorApplicability;
use PHPUnit\Framework\TestCase;

class DamageFactorApplicabilityTest extends TestCase
{
    private function element(): array
    {
        $screening = (new ComponentMechanismScreening)->evaluate([], [], []);
        foreach ($screening['mechanisms'] as &$method) {
            foreach ($method['sides'] as &$side) {
                $side['state'] = 'inactive';
            }
        }
        unset($method, $side);

        return ['active' => true, ...$screening];
    }

    private function state(array &$element, string $code, string $side, string $state): void
    {
        foreach ($element['mechanisms'] as &$method) {
            if ($method['id'] !== $code) {
                continue;
            }
            $method['sides'] = array_values(array_filter($method['sides'], fn ($row) => $row['side'] !== $side));
            $method['sides'][] = ['side' => $side, 'state' => $state];
        }
        unset($method);
    }

    public function test_external_process_activity_cannot_activate_internal_thinning_or_cracking_factors(): void
    {
        $element = $this->element();
        $this->state($element, 'hcl', 'external', 'active');
        $this->state($element, 'htha', 'external', 'active');
        $this->state($element, 'ssc', 'internal', 'potential');
        $this->state($element, 'extcl', 'external', 'active');
        $result = (new DamageFactorApplicability)->resolve($element, 'shell');
        $this->assertSame('inactive', $result['factors']['thin']['state']);
        $this->assertSame('inactive', $result['factors']['htha']['state']);
        $this->assertSame(['ecl'], $result['active']);
        $this->assertSame(['ssc'], $result['potential']);
        $this->assertFalse($result['applicability_known']);
        $this->assertSame([['mechanism' => 'extcl', 'side' => 'external', 'state' => 'active']], $result['factors']['ecl']['sources']);
        $this->state($element, 'hcl', 'internal', 'active');
        $this->assertSame('active', (new DamageFactorApplicability)->resolve($element, 'shell')['factors']['thin']['state']);
    }

    public function test_ordinary_flange_exclusion_does_not_exclude_blind_flange_and_archived_elements_are_separate(): void
    {
        $element = $this->element();
        $this->state($element, 'bf', 'through', 'active');
        $rules = new DamageFactorApplicability;
        $this->assertSame(['bf'], $rules->resolve($element, 'flanged_cap')['active']);
        $flange = $rules->resolve($element, 'flange');
        $this->assertSame([], $flange['active']);
        $this->assertSame([], $flange['potential']);
        $this->assertSame('ordinary_flange', $flange['excluded_reason']);
        $this->assertSame('inactive_element', $rules->resolve([...$element, 'active' => false], 'shell')['excluded_reason']);
    }

    public function test_missing_screening_never_becomes_confirmed_zero_and_duplicate_sides_are_rejected(): void
    {
        $rules = new DamageFactorApplicability;
        $missing = $rules->resolve(['active' => true, 'mechanisms' => []], null);
        $this->assertCount(20, $missing['potential']);
        $this->assertFalse($missing['applicability_known']);
        $element = $this->element();
        $element['mechanisms'][0]['sides'][] = ['side' => 'internal', 'state' => 'active'];
        $this->expectException(\InvalidArgumentException::class);
        $rules->resolve($element, 'shell');
    }

    public function test_missing_element_status_is_not_treated_as_a_confirmed_archival_exclusion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DamageFactorApplicability)->resolve(['mechanisms' => []], null);
    }
}
