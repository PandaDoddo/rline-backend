<?php

namespace Tests\Unit;

use App\Modules\Equipment\Domain\Screening\ComponentMechanismScreening;
use App\Modules\Equipment\Domain\Screening\ComponentQuestionEvaluator;
use App\Modules\Equipment\Domain\Screening\ComponentScreeningSchema;
use PHPUnit\Framework\TestCase;

class ComponentMechanismScreeningTest extends TestCase
{
    public function test_all_mechanisms_match_independent_old_component_reference_cases(): void
    {
        $reference = json_decode(file_get_contents(__DIR__.'/../Fixtures/old-component-screening.json'), true, flags: JSON_THROW_ON_ERROR);
        $engine = new ComponentMechanismScreening;
        $this->assertCount(24, $reference['cases']);
        foreach ($reference['cases'] as $index => $case) {
            $result = $engine->evaluate($case['context'], $case['internal'], $case['external']);
            foreach ($result['mechanisms'] as $mechanism) {
                $expected = $case['expected'][$mechanism['id']];
                $this->assertSame($expected['state'], $mechanism['state'], 'Case '.$index.' '.$mechanism['id']);
                $actual = [];
                foreach ($mechanism['sides'] as $side) {
                    $actual[$side['side']] = $side['state'];
                }
                $this->assertSame($expected['sides'], $actual, 'Sides: case '.$index.' '.$mechanism['id']);
            }
        }
    }

    private function context(): array
    {
        return ['material' => ['kind' => 'сталь', 'subtype' => 'углеродистая', 'rbi_group' => 'углеродистая', 'rbi_crCO2' => 'не применимо', 'rbi_cr885' => 'не применимо'],
            'parameters' => ['temperature_max_c' => 20, 'pressure_operating_mpa' => 0.4, 'temperature_min_c' => -40, 'temperature_mdmt_c' => -20],
            'insulated' => false, 'external_process' => false, 'soil_contact' => false, 'tank_floor' => false, 'pipeline' => false, 'vibration_present' => null];
    }

    private function flow(string $id, array $values): array
    {
        return ['id' => $id, 'category' => 'main', 'medium_kind' => 'fluid', 'screening' => ['values' => $values]];
    }

    private function find(array $result, string $id): array
    {
        return array_column($result['mechanisms'], null, 'id')[$id];
    }

    public function test_environment_and_component_conditions_form_independent_physical_sides(): void
    {
        $engine = new ComponentMechanismScreening;
        $inside = $this->flow('inside', ['hcl' => false, 'water' => true, 'h2s' => true]);
        $outside = $this->flow('outside', ['hcl' => true, 'water' => true, 'ph' => 1]);
        $result = $engine->evaluate($this->context(), [$inside], [$outside]);
        $this->assertCount(32, $result['mechanisms']);
        $ssc = $this->find($result, 'ssc');
        $this->assertSame('active', $ssc['sides'][0]['state']);
        $hcl = $this->find($result, 'hcl');
        $sides = array_column($hcl['sides'], null, 'side');
        $this->assertSame('inactive', $sides['internal']['state']);
        $this->assertSame('active', $sides['external']['state']);
        $this->assertSame('active', $hcl['state']);
        $this->assertSame('active', $this->find($result, 'extf')['state']);
        $this->assertSame('inactive', $this->find($result, 'cuif')['state']);
        $this->assertSame('inactive', $this->find($result, 'mfat')['state']);
        $shared = $engine->evaluate($this->context(), [$outside], [$outside]);
        $this->assertSame(['internal', 'external'], array_column($this->find($shared, 'hcl')['sides'], 'side'));
    }

    public function test_missing_card_data_and_missing_flow_stay_potential_without_guessing_material_or_temperature(): void
    {
        $engine = new ComponentMechanismScreening;
        $context = $this->context();
        $context['parameters'] = [];
        $context['material'] = null;
        $context['insulated'] = null;
        $flow = $this->flow('known', ['h2s' => true, 'water' => true]);
        $flow['attributes'] = ['temperature_c' => 100, 'pressure_mpa' => 1];
        $result = $engine->evaluate($context, [$flow], []);
        $ssc = $this->find($result, 'ssc');
        $this->assertSame('potential', $ssc['state']);
        $questions = array_column($ssc['sides'][0]['runs'][0]['questions'], null, 'id');
        $this->assertSame('unknown', $questions['ssc|1']['status']);
        $this->assertSame('unknown', $questions['ssc|3']['status']);
        $this->assertSame('potential', $this->find($engine->evaluate($this->context(), [], []), 'hcl')['state']);
        $context = $this->context();
        $context['external_process'] = true;
        $this->assertSame('inactive', $this->find($engine->evaluate($context, [], []), 'extf')['state']);
    }

    public function test_negative_temperature_threshold_unknowns_and_false_vibration_are_distinct(): void
    {
        $engine = new ComponentMechanismScreening;
        $context = $this->context();
        $context['parameters']['temperature_max_c'] = -12;
        $this->assertSame('active', $this->find($engine->evaluate($context, [], []), 'extf')['state']);
        $context['parameters']['temperature_max_c'] = -12.01;
        $this->assertSame('inactive', $this->find($engine->evaluate($context, [], []), 'extf')['state']);
        $context['pipeline'] = true;
        $this->assertSame('potential', $this->find($engine->evaluate($context, [], []), 'mfat')['state']);
        $context['vibration_present'] = false;
        $this->assertSame('inactive', $this->find($engine->evaluate($context, [], []), 'mfat')['state']);
    }

    public function test_assigned_flow_temperature_is_a_fallback_and_hydrogen_input_never_overrides_explicit_absence(): void
    {
        $questions = new ComponentQuestionEvaluator;
        $schema = array_column(ComponentScreeningSchema::metadata()['mechanisms'], null, 'id');
        $temperature = $schema['htha']['questions'][1];
        $hydrogen = $schema['htha']['questions'][2];
        $context = $this->context();
        $context['parameters']['temperature_max_c'] = null;
        $context['hydrogen_partial_pressure_mpa'] = 0.5;
        $flow = [...$this->flow('explicit', ['h2' => true]), 'parameters' => ['temperature_max_c' => 200]];
        $this->assertSame('active', $questions->evaluate($temperature, $context, $flow)['status']);
        $this->assertSame('flow.temperature_max_c', $questions->evaluate($temperature, $context, $flow)['source']);
        $context['parameters']['temperature_max_c'] = 0;
        $this->assertSame(0, $questions->evaluate($temperature, $context, $flow)['value']);
        $this->assertSame('inactive', $questions->evaluate($temperature, $context, $flow)['status']);
        $context['parameters']['temperature_max_c'] = null;
        $this->assertSame('unknown', $questions->evaluate($temperature, $context, [...$flow, 'usable' => false])['status']);
        $answer = $questions->evaluate($hydrogen, $context, $flow)['conditions'][0];
        $this->assertSame('active', $answer['status']);
        $this->assertSame('equipment.hydrogen_partial_pressure_mpa', $answer['source']);
        $this->assertSame('inactive', $questions->evaluate($hydrogen, $context, $this->flow('absent', ['h2' => false]))['status']);
        $this->assertSame('unknown', $questions->evaluate($hydrogen, $context, $this->flow('unknown', []))['status']);
        $this->assertSame('inactive', $questions->evaluate($hydrogen, $context, $this->flow('known', ['h2' => true, 'pH2' => 0]))['status']);
    }

    public function test_overrides_are_scoped_validated_and_hashed_independently_of_object_key_order(): void
    {
        $engine = new ComponentMechanismScreening;
        $flow = $this->flow('flow', ['hcl' => true, 'water' => true, 'ph' => 1]);
        $override = ['hcl|external' => ['state' => 'inactive', 'reason' => 'Подтверждённое исключение']];
        $result = $engine->evaluate($this->context(), [$flow], [$flow], $override);
        $sides = array_column($this->find($result, 'hcl')['sides'], null, 'side');
        $this->assertSame('active', $sides['internal']['state']);
        $this->assertSame('inactive', $sides['external']['state']);
        $this->assertSame('active', $sides['external']['automatic_state']);
        $this->assertSame($result['input_signature'], $engine->evaluate(array_reverse($this->context(), true), [$flow], [$flow], $override)['input_signature']);
        $this->expectException(\InvalidArgumentException::class);
        $engine->evaluate($this->context(), [$flow], [], ['hcl|external' => ['state' => 'active', 'reason' => 'Не назначена наружная среда']]);
    }

    public function test_unaccepted_or_repair_streams_cannot_produce_a_confirmed_active_process_mechanism(): void
    {
        $engine = new ComponentMechanismScreening;
        $flow = $this->flow('flow', ['hcl' => true, 'water' => true, 'ph' => 1]);
        $flow['usable'] = false;
        $this->assertSame('potential', $this->find($engine->evaluate($this->context(), [$flow], []), 'hcl')['state']);
        $flow['category'] = 'repair';
        $this->expectException(\InvalidArgumentException::class);
        $engine->evaluate($this->context(), [$flow], []);
    }
}
