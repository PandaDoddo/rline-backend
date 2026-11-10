<?php

namespace App\Modules\Equipment\Domain\Screening;

use App\Modules\Shared\Domain\ContentHash;
use App\Modules\Shared\Domain\ValueEquality;
use InvalidArgumentException;

/** AND within one stream, OR between streams, independent physical sides. */
final class ComponentMechanismScreening
{
    public function __construct(private readonly ComponentQuestionEvaluator $questions = new ComponentQuestionEvaluator) {}

    public function evaluate(array $context, array $internal, array $external, array $overrides = [], bool $details = true): array
    {
        $internal = $this->flows($internal);
        $external = $this->flows($external);
        $inside = array_column($internal, null, 'id');
        foreach ($external as $flow) {
            if (isset($inside[$flow['id']]) && ! ValueEquality::same($flow, $inside[$flow['id']])) {
                throw new InvalidArgumentException('Один поток не может иметь два разных снимка входов.');
            }
        }
        $mechanisms = [];
        $used = [];
        foreach (ComponentScreeningSchema::metadata()['mechanisms'] as $mechanism) {
            $buckets = $mechanism['side_policy'] === 'process' ? ['internal' => $internal] : [$mechanism['side_policy'] => [null]];
            if ($mechanism['side_policy'] === 'process' && $external) {
                $buckets['external'] = $external;
            }
            $sides = [];
            foreach ($buckets as $side => $flows) {
                $runs = [];
                $conditions = [];
                $hasFlow = (bool) $flows;
                foreach ($flows ?: [null] as $flow) {
                    $questions = [];
                    foreach ($mechanism['questions'] as $question) {
                        $answer = $this->questions->evaluate($question, $context, $flow);
                        $questions[] = $details ? ['id' => $question['id'], 'label' => $question['label'], ...$answer] : ['status' => $answer['status']];
                        if (! $details && $answer['status'] === 'inactive') {
                            break;
                        }
                    }
                    $condition = ComponentQuestionEvaluator::worst(array_column($questions, 'status'));
                    if ($mechanism['side_policy'] === 'process' && (! $hasFlow || ($flow['usable'] ?? true) === false) && $condition !== 'inactive') {
                        $condition = 'unknown';
                    }
                    $conditions[] = $condition;
                    if ($details) {
                        $runs[] = ['flow_id' => $flow['id'] ?? null, 'condition' => $condition, 'questions' => $questions];
                    }
                    if (! $details && $condition === 'active') {
                        break;
                    }
                }
                $condition = ComponentQuestionEvaluator::best($conditions);
                $automatic = $this->state($condition);
                $key = $mechanism['id'].'|'.$side;
                $override = $overrides[$key] ?? null;
                if ($override !== null) {
                    if (! is_array($override) || ! in_array($override['state'] ?? null, ['active', 'potential', 'inactive'], true) || ! is_string($override['reason'] ?? null) || trim($override['reason']) === '' || mb_strlen($override['reason']) > 5000) {
                        throw new InvalidArgumentException('Для ручного статуса МД укажите допустимое значение и обоснование.');
                    }
                    $used[$key] = true;
                }
                $sides[] = ['side' => $side, 'automatic_state' => $automatic, 'state' => $override['state'] ?? $automatic, 'condition' => $condition, 'override' => $override, 'flow_count' => count(array_filter($flows)), 'runs' => $runs];
            }
            $states = array_column($sides, 'state');
            $automatic = array_column($sides, 'automatic_state');
            $mechanisms[] = ['id' => $mechanism['id'], 'label' => $mechanism['label'], 'group' => $mechanism['group'], 'component_only' => $mechanism['component_only'],
                'state' => $this->top($states), 'automatic_state' => $this->top($automatic), 'sides' => $sides];
        }
        if (array_diff(array_keys($overrides), array_keys($used))) {
            throw new InvalidArgumentException('Ручной статус относится к неизвестному механизму или неприменимой физической стороне.');
        }

        return ['engine_version' => ComponentScreeningSchema::VERSION, 'input_signature' => ContentHash::of(['schema' => ComponentScreeningSchema::metadata(), 'context' => $context, 'internal' => $internal, 'external' => $external, 'overrides' => $overrides]), 'mechanisms' => $mechanisms];
    }

    private function flows(array $inputs): array
    {
        $rows = [];
        foreach ($inputs as $flow) {
            if (! is_array($flow) || ! is_string($flow['id'] ?? null) || $flow['id'] === '' || ! in_array($flow['category'] ?? null, ['main', 'alternative', 'auxiliary'], true) || ($flow['medium_kind'] ?? null) !== 'fluid') {
                throw new InvalidArgumentException('МД по технологическим средам требует явного назначенного технологического потока.');
            }
            if (isset($rows[$flow['id']])) {
                throw new InvalidArgumentException('Один поток не должен повторяться внутри физической стороны.');
            }
            $rows[$flow['id']] = $flow;
        }
        ksort($rows, SORT_STRING);

        return array_values($rows);
    }

    private function state(string $condition): string
    {
        return match ($condition) {
            'active' => 'active','inactive' => 'inactive',default => 'potential'
        };
    }

    private function top(array $states): string
    {
        return in_array('active', $states, true) ? 'active' : (in_array('potential', $states, true) ? 'potential' : 'inactive');
    }
}
