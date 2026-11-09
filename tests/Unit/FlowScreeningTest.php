<?php

namespace Tests\Unit;

use App\Modules\ProcessFlows\Domain\FlowScreening;
use Tests\TestCase;

class FlowScreeningTest extends TestCase
{
    public function test_all_primary_mechanisms_match_existing_javascript_reference(): void
    {
        $engine = new FlowScreening;
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/flow-screening-reference.json')), true, flags: JSON_THROW_ON_ERROR) as $index => $case) {
            $actual = collect($engine->evaluate($case)['mechanisms'])->pluck('status', 'id')->all();
            $this->assertSame($case['expected'], $actual, 'Reference case '.$index);
        }
    }

    public function test_boundaries_attention_ranges_unknowns_and_strict_zero(): void
    {
        $engine = new FlowScreening;
        foreach ([['>', 0, [0], [10], 'inactive'], ['>', 0.001, [0], [], 'active'], ['>', 10, [10], [], 'active'],
            ['<', 10, [10], [], 'active'], ['>', 8, [10], [2], 'attention'], ['>', 7.99, [10], [2], 'inactive'],
            ['in', 5, [5, 10], [1, 2], 'active'], ['in', 12, [5, 10], [1, 2], 'attention'], ['in', 12.01, [5, 10], [1, 2], 'inactive'],
            ['in', 4, [5, 10], [1, 2], 'attention'], ['<', null, [10], [], 'unknown']] as [$op, $value, $threshold, $near, $expected]) {
            $this->assertSame($expected, $engine->compare($op, $value, $threshold, $near));
        }
    }

    public function test_hydrogen_presence_chloride_presence_and_no_substitution_of_missing_values(): void
    {
        $engine = new FlowScreening;
        $find = fn ($state, $id) => collect($engine->evaluate($state)['mechanisms'])->firstWhere('id', $id);
        $this->assertSame('potential', $find(null, 'hcl')['status']);
        $this->assertSame('active', $find(['values' => ['hcl' => true, 'water' => true, 'ph' => 7]], 'hcl')['status']);
        $this->assertSame('inactive', $find(['values' => ['hcl' => true, 'water' => false]], 'hcl')['status']);
        $htha = collect($engine->schema()['mechanisms'])->first(fn ($m) => in_array('htha', $m['damage_mechanisms']));
        $this->assertNotNull($htha);
        $this->assertSame('inactive', $find(['values' => ['h2' => false, 'pH2' => 10]], $htha['id'])['status']);
        $this->assertSame('potential', $find(['values' => ['pH2' => 10]], $htha['id'])['status']);
        foreach ($engine->evaluate(['values' => ['clOn' => false, 'cl' => 1000]])['mechanisms'] as $mechanism) {
            foreach ($mechanism['questions'] as $question) {
                foreach ($question['conditions'] as $condition) {
                    if ($question['scope'] === 'flow' && $condition['field'] === 'cl') {
                        $this->assertSame(0, $condition['value']);
                    }
                }
            }
        }
        $a = $engine->evaluate(['values' => ['hcl' => true, 'water' => true], 'comments' => ['hcl' => 'note']]);
        $b = $engine->evaluate(['values' => ['water' => true, 'hcl' => true]]);
        $this->assertSame($a['signature'], $b['signature']);
        $this->assertCount(21, $a['mechanisms']);
    }
}
