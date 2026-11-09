<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

/** Scores a versioned questionnaire; partial progress is not an accepted PoF input. */
final class ManagementAssessment
{
    public function calculate(array $sections, array $answers): array
    {
        $byId = [];
        foreach ($answers as $answer) {
            if (! isset($answer['question_id']) || isset($byId[$answer['question_id']])) {
                throw new InvalidArgumentException('Повторяющийся или отсутствующий идентификатор вопроса.');
            }
            $byId[$answer['question_id']] = $answer;
        }
        $known = [];
        $scores = [];
        $missing = [];
        foreach ($sections as $section) {
            $maximum = 0;
            $points = 0;
            $answered = 0;
            $total = 0;
            foreach ($section['questions'] as $question) {
                $known[] = $question['id'];
                if ($question['kind'] === 'heading') {
                    continue;
                }
                $total++;
                $maximum += $question['maximum'];
                $answer = $byId[$question['id']] ?? null;
                $value = $answer['value'] ?? null;
                $state = $answer['state'] ?? 'unanswered';
                if (! in_array($state, ['answered', 'unanswered', 'not_applicable'], true)) {
                    throw new InvalidArgumentException('Неизвестное состояние ответа.');
                }
                if ($state === 'unanswered') {
                    $missing[] = $question['id'];

                    continue;
                }
                if ($state === 'not_applicable') {
                    if (trim($answer['comment'] ?? '') === '') {
                        throw new InvalidArgumentException('Укажите обоснование неприменимости вопроса '.$question['id']);
                    }
                    $points += $question['maximum'];
                    $answered++;

                    continue;
                }
                if ($question['kind'] === 'boolean') {
                    if (! is_bool($value)) {
                        throw new InvalidArgumentException('Ожидается да/нет для вопроса '.$question['id']);
                    }
                    $points += $value ? $question['maximum'] : 0;
                } elseif ($question['kind'] === 'score') {
                    if ((! is_int($value) && ! is_float($value)) || ! is_finite($value) || ! in_array($value, array_column($question['options'], 'points'))) {
                        throw new InvalidArgumentException('Выберите допустимую оценку вопроса '.$question['id']);
                    }
                    $points += $value;
                } else {
                    if (! is_array($value) || ! array_is_list($value) || count(array_unique($value, SORT_REGULAR)) !== count($value)) {
                        throw new InvalidArgumentException('Пункты ответа должны быть уникальным списком.');
                    }
                    $selected = [];
                    $sum = 0;
                    foreach ($value as $index) {
                        if (! is_int($index) || ! isset($question['options'][$index])) {
                            throw new InvalidArgumentException('Неизвестный пункт вопроса '.$question['id']);
                        }
                        $option = $question['options'][$index];
                        $selected[] = $option;
                        $sum += $option['points'];
                    }
                    if (count($selected) > 1 && count(array_filter($selected, fn ($option) => $option['exclusive'])) > 0) {
                        throw new InvalidArgumentException('Пункт «не применяется» исключает остальные пункты.');
                    }
                    $points += min($sum, $question['maximum']);
                }
                $answered++;
            }
            $score = $maximum > 0 ? $points / $maximum * 100 : 0;
            $scores[] = ['id' => $section['id'], 't' => $section['title'], 'w' => $section['weight'], 'mx' => $maximum, 'f' => $points, 'sc' => $score, 'ws' => $score * $section['weight'], 'ans' => $answered, 'tot' => $total];
        }
        if (array_diff(array_keys($byId), $known)) {
            throw new InvalidArgumentException('Ответ ссылается на неизвестный вопрос.');
        }
        foreach ($sections as $section) {
            foreach ($section['questions'] as $question) {
                if ($question['kind'] === 'heading' && isset($byId[$question['id']])) {
                    throw new InvalidArgumentException('Заголовок не является вопросом.');
                }
            }
        }
        $provisional = array_sum(array_column($scores, 'ws'));
        $ready = $missing === [];
        $score = $ready ? $provisional : null;

        return ['method' => 'management-api581-4-legacy-v1', 'ready' => $ready, 'missing' => $missing, 'sections' => $scores, 'answered' => array_sum(array_column($scores, 'ans')), 'total' => array_sum(array_column($scores, 'tot')),
            'score' => $score, 'provisional_score' => $provisional, 'fms_pof' => $score === null ? null : 2.38 * pow(2.7182818284, -0.012 * $score), 'fms_checklist_legacy' => $score === null ? null : pow(10, -0.02 * $score + 1),
            'factor_policy' => 'PoF uses pof-old-v1. The legacy questionnaire factor is shown separately and never silently substituted.'];
    }
}
