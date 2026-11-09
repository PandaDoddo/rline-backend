<?php

namespace App\Modules\Rbi\Domain;

final class ManagementHistory
{
    public function changes(array $before, array $after, array $sections): array
    {
        $old = array_column($before['answers'] ?? [], null, 'question_id');
        $new = array_column($after['answers'] ?? [], null, 'question_id');
        $changes = [];
        foreach ($sections as $section) {
            foreach ($section['questions'] as $question) {
                if ($question['kind'] === 'heading') {
                    continue;
                }
                $id = $question['id'];
                $a = $old[$id] ?? ['state' => 'unanswered', 'value' => null, 'comment' => null];
                $b = $new[$id] ?? ['state' => 'unanswered', 'value' => null, 'comment' => null];
                foreach (['state' => 'Состояние', 'value' => 'Ответ', 'comment' => 'Комментарий'] as $key => $label) {
                    $from = $this->text($a[$key] ?? null, $key, $question);
                    $to = $this->text($b[$key] ?? null, $key, $question);
                    if ($from !== $to) {
                        $changes[] = ['key' => $id.'|'.$key, 'label' => $id.' · '.$label.' · '.$question['text'], 'from' => $from, 'to' => $to];
                    }
                }
            }
        }

        return $changes;
    }

    private function text(mixed $value, string $key, array $question): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($key === 'state') {
            return ['answered' => 'есть ответ', 'unanswered' => 'нет ответа', 'not_applicable' => 'не применимо'][$value] ?? (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }
        if (is_array($value)) {
            $value = array_values($value);
            sort($value);

            return $value === [] ? 'ни один пункт не выполнен' : implode('; ', array_map(fn ($index) => $question['options'][$index]['label'] ?? (string) $index, $value));
        }

        return (string) $value;
    }
}
