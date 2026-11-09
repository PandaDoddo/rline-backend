<?php

namespace Tests\Unit;

use App\Modules\Rbi\Domain\ManagementAssessment;
use PHPUnit\Framework\TestCase;

class ManagementAssessmentTest extends TestCase
{
    private function schema(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../resources/rbi/management-questionnaire-v1.json'), true, flags: JSON_THROW_ON_ERROR)['sections'];
    }

    public function test_questionnaire_scores_and_section_weights_match_legacy(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/management-reference.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            $result = (new ManagementAssessment)->calculate($this->schema(), $case['answers']);
            $this->assertTrue($result['ready']);
            $this->assertEqualsWithDelta($case['expected']['pscore'], $result['score'], 1e-12);
            $this->assertEqualsWithDelta($case['expected']['fms'], $result['fms_checklist_legacy'], 1e-12);
            $this->assertSame($case['expected']['ans'], $result['answered']);
            $this->assertSame($case['expected']['tot'], $result['total']);
            foreach ($case['expected']['secs'] as $index => $section) {
                foreach (['mx', 'f', 'sc', 'ws', 'ans', 'tot'] as $key) {
                    $this->assertEqualsWithDelta($section[$key], $result['sections'][$index][$key], 1e-12);
                }
            }
        }
    }

    public function test_partial_assessment_has_progress_but_no_ready_score_or_management_factor(): void
    {
        $result = (new ManagementAssessment)->calculate($this->schema(), [['question_id' => '1.1', 'state' => 'answered', 'value' => true, 'comment' => null]]);
        $this->assertFalse($result['ready']);
        $this->assertSame(1, $result['answered']);
        $this->assertNull($result['score']);
        $this->assertNull($result['fms_pof']);
        $this->assertNull($result['fms_checklist_legacy']);
    }
}
