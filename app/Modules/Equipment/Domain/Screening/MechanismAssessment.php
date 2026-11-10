<?php

namespace App\Modules\Equipment\Domain\Screening;

use App\Modules\Shared\Domain\ContentHash;

/** Request-local memoization, content-bound approvals and conservative stale overrides. */
final class MechanismAssessment
{
    public function __construct(private readonly ComponentMechanismScreening $engine = new ComponentMechanismScreening) {}

    public function evaluate(MechanismContextFactory $factory, array $physical, array $facts, bool $pipeline): array
    {
        $overrides = [];
        $notes = [];
        foreach ($facts['overrides'] as $row) {
            $overrides[$row['component_id']][] = $row;
        }
        foreach ($facts['question_notes'] as $row) {
            $notes[$row['component_id']][] = $row;
        }
        $reviews = array_column($facts['reviews'], null, 'component_id');
        $cache = [];
        $finalCache = [];
        $elements = [];
        $activity = [];
        $allComplete = true;
        $hasCurrent = false;
        $enrichedOverrides = [];
        $processed = [];
        $activityRules = new MechanismActivity;
        foreach ($physical as $element) {
            if (! $element['current_element']) {
                continue;
            }
            $id = $element['component_id'];
            $context = $factory->element($id, $pipeline);
            $review = $reviews[$id] ?? null;
            if (! $context['active']) {
                $elements[] = ['component_id' => $id, 'scope_id' => $context['scope_id'], 'active' => false, 'complete' => false, 'source_signature' => null, 'calculation_signature' => null, 'input_signature' => null, 'material_ref' => $context['material_ref'], 'mechanisms' => [],
                    'review' => ['verified' => false, 'outdated' => $review !== null, 'verified_by' => $review['verified_by'] ?? null, 'verified_at' => $review['verified_at'] ?? null]];
                $activity[$id] = array_fill_keys(['thin', 'ext', 'cui', 'cl', 'scc', 'hic', 'aus'], false);

                continue;
            }
            $hasCurrent = true;
            $base = $cache[$context['key']] ??= $this->engine->evaluate($context['context'], $context['internal'], $context['external'], [], false);
            $valid = [];
            $sides = [];
            foreach ($base['mechanisms'] as $mechanism) {
                foreach ($mechanism['sides'] as $side) {
                    $sides[$mechanism['id'].'|'.$side['side']] = true;
                }
            }
            foreach ($overrides[$id] ?? [] as $row) {
                $key = $row['mechanism_code'].'|'.$row['side'];
                $stale = $row['source_signature'] !== $base['input_signature'] || ! isset($sides[$key]);
                $enrichedOverrides[] = [...$row, 'stale' => $stale];
                $processed[$id.'|'.$key] = true;
                if (! $stale) {
                    $valid[$key] = ['state' => $row['state'], 'reason' => $row['reason']];
                }
            }
            $result = $base;
            if ($valid) {
                $key = $context['key'].':'.ContentHash::of($valid);
                $result = $finalCache[$key] ??= $this->engine->evaluate($context['context'], $context['internal'], $context['external'], $valid, false);
            }
            $plainNotes = array_map(fn ($row) => array_intersect_key($row, array_flip(['question_id', 'side', 'flow_id', 'note'])), $notes[$id] ?? []);
            usort($plainNotes, fn ($a, $b) => strcmp($a['question_id'].'|'.$a['side'].'|'.($a['flow_id'] ?? ''), $b['question_id'].'|'.$b['side'].'|'.($b['flow_id'] ?? '')));
            $signature = ContentHash::of(['calculation' => $result['input_signature'], 'question_notes' => $plainNotes, 'equipment_notes' => array_intersect_key($facts['inputs'], array_flip(['vibration_notes', 'hydrogen_notes'])), 'component_notes' => $context['notes']]);
            $complete = ! in_array('potential', array_column($result['mechanisms'], 'state'), true);
            $allComplete = $allComplete && $complete;
            $elements[] = ['component_id' => $id, 'scope_id' => $context['scope_id'], 'active' => true, 'complete' => $complete, 'source_signature' => $base['input_signature'], 'calculation_signature' => $result['input_signature'], 'input_signature' => $signature, 'material_ref' => $context['material_ref'], 'mechanisms' => $result['mechanisms'],
                'review' => ['verified' => $review !== null && $review['input_signature'] === $signature, 'outdated' => $review !== null && $review['input_signature'] !== $signature, 'verified_by' => $review['verified_by'] ?? null, 'verified_at' => $review['verified_at'] ?? null]];
            $activity[$id] = $activityRules->categories($result, $element['insulated'] ?? null, $context['type_code'] === 'flange');
        }
        foreach ($facts['overrides'] as $row) {
            if (! isset($processed[$row['component_id'].'|'.$row['mechanism_code'].'|'.$row['side']])) {
                $enrichedOverrides[] = [...$row, 'stale' => true];
            }
        }

        return ['engine_version' => ComponentScreeningSchema::VERSION, 'complete' => $hasCurrent && $allComplete, 'elements' => $elements, 'activity' => $activity, 'overrides' => $enrichedOverrides];
    }
}
