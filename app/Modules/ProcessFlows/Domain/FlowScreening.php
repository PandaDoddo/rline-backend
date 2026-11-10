<?php

namespace App\Modules\ProcessFlows\Domain;

use Illuminate\Validation\ValidationException;

class FlowScreening
{
    private ?array $baseline = null;

    public function schema(): array
    {
        return $this->baseline ??= json_decode(file_get_contents(resource_path('catalogs/flow-screening-v2.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function eligible(string $category, string $medium, string $kind = 'flow'): bool
    {
        return $kind === 'flow' && $medium === 'fluid' && in_array($category, ['main', 'alternative', 'auxiliary'], true);
    }

    public function merge(?array $current, array $patch, int $actor): array
    {
        $current ??= ['values' => [], 'comments' => [], 'thresholds' => []];
        $questions = [];
        foreach ($this->schema()['mechanisms'] as $mechanism) {
            foreach ($mechanism['questions'] as $question) {
                $questions[$question['id']] = $question;
            }
        }
        $fields = array_column($this->schema()['fields'], null, 'id');
        foreach (['values' => $fields, 'comments' => $fields + $questions, 'thresholds' => $questions] as $section => $known) {
            foreach ($patch[$section] ?? [] as $key => $value) {
                $path = 'screening.'.$section.'.'.$key;
                if (! isset($known[$key])) {
                    throw ValidationException::withMessages([$path => 'Неизвестный параметр или вопрос.']);
                }
                if ($section === 'values' && $value !== null) {
                    $value = $fields[$key]['type'] === 'boolean' ? (bool) $value : (float) $value;
                }
                if ($section === 'thresholds' && $value !== null) {
                    $condition = collect($questions[$key]['conditions'])->first(fn ($c) => $c['operator'] !== 'yes');
                    if (! $condition) {
                        throw ValidationException::withMessages([$path => 'У вопроса нет числового порога.']);
                    }
                    $size = $condition['operator'] === 'in' ? 2 : 1;
                    $stored = $current['thresholds'][$key] ?? [];
                    $threshold = $value['threshold'] ?? $stored['threshold'] ?? $condition['threshold'];
                    $near = $value['near'] ?? $stored['near'] ?? $condition['near'];
                    if (count($threshold) !== $size || count($near) > $size || ($size === 2 && $threshold[0] > $threshold[1]) || array_filter($near, fn ($v) => $v < 0)) {
                        throw ValidationException::withMessages([$path => 'Проверьте границы порога и неотрицательную зону внимания.']);
                    }
                    $value = ['threshold' => array_map('floatval', $threshold), 'near' => array_map('floatval', $near)];
                }
                if ($value === null) {
                    unset($current[$section][$key]);
                } else {
                    $current[$section][$key] = $value;
                }
            }
        }
        $current['updated_by'] = $actor;
        $current['updated_at'] = now()->toIso8601String();

        return $current;
    }

    public function evaluate(?array $state): array
    {
        $state ??= [];
        $values = $state['values'] ?? [];
        ksort($values);
        $thresholds = $state['thresholds'] ?? [];
        ksort($thresholds);
        $results = [];
        foreach ($this->schema()['mechanisms'] as $mechanism) {
            if ($mechanism['component_only']) {
                continue;
            }
            $questions = [];
            $statuses = [];
            foreach ($mechanism['questions'] as $question) {
                $conditions = [];
                foreach ($question['conditions'] as $condition) {
                    $override = ($state['thresholds'] ?? [])[$question['id']] ?? [];
                    $threshold = $override['threshold'] ?? $condition['threshold'];
                    $near = $override['near'] ?? $condition['near'];
                    $value = $values[$condition['field']] ?? null;
                    $reason = null;
                    if ($question['scope'] === 'component') {
                        $status = 'component_required';
                    } elseif ($condition['operator'] === 'yes') {
                        $status = $value === true ? 'active' : ($value === false ? 'inactive' : 'unknown');
                    } elseif ($condition['field'] === 'pH2' && ($values['h2'] ?? null) !== true) {
                        $status = ($values['h2'] ?? null) === false ? 'inactive' : 'unknown';
                        $reason = ($values['h2'] ?? null) === false ? 'Водорода нет' : 'Присутствие водорода неизвестно';
                    } elseif ($condition['field'] === 'cl' && (($values['clOn'] ?? null) === false)) {
                        $value = 0;
                        $status = $this->compare($condition['operator'], 0, $threshold, $near);
                        $reason = 'Хлоридов нет: для условия принято 0';
                    } elseif ($condition['field'] === 'cl' && ! ($values['clOn'] ?? array_key_exists('cl', $values))) {
                        $status = 'unknown';
                        $reason = 'Присутствие хлоридов неизвестно';
                    } else {
                        $status = $this->compare($condition['operator'], $value, $threshold, $near);
                    }
                    if ($question['scope'] === 'flow') {
                        $statuses[] = $status;
                    }
                    $conditions[] = ['field' => $condition['field'], 'operator' => $condition['operator'], 'value' => $value, 'threshold' => $threshold, 'near' => $near, 'status' => $status, 'reason' => $reason];
                }
                $questions[] = ['id' => $question['id'], 'label' => $question['label'], 'scope' => $question['scope'], 'comment' => ($state['comments'] ?? [])[$question['id']] ?? null, 'overridden' => isset($state['thresholds'][$question['id']]), 'conditions' => $conditions];
            }
            $status = in_array('inactive', $statuses, true) ? 'inactive' : (count($statuses) === 0 || array_diff($statuses, ['active']) ? 'potential' : 'active');
            $results[] = ['id' => $mechanism['id'], 'label' => $mechanism['label'], 'source' => $mechanism['source'], 'status' => $status, 'component_refinement_required' => count(array_filter($mechanism['questions'], fn ($q) => $q['scope'] === 'component')) > 0, 'questions' => $questions];
        }

        return ['engine_version' => $this->schema()['version'], 'signature' => hash('sha256', json_encode([$this->schema(), $values, $thresholds], JSON_PRESERVE_ZERO_FRACTION)), 'mechanisms' => $results];
    }

    public function compare(string $operator, float|int|null $value, array $threshold, array $near): string
    {
        if ($value === null || $threshold === []) {
            return 'unknown';
        }
        $a = $threshold[0];
        $z = $near[0] ?? 0;
        if ($operator === '>' && $a == 0) {
            return $value > 0 ? 'active' : 'inactive';
        }
        if ($operator === '>') {
            return $value >= $a ? 'active' : ($z > 0 && $value >= $a - $z ? 'attention' : 'inactive');
        }
        if ($operator === '<') {
            return $value <= $a ? 'active' : ($z > 0 && $value <= $a + $z ? 'attention' : 'inactive');
        }
        $b = $threshold[1] ?? $a;
        $zb = $near[1] ?? $z;

        return $value >= $a && $value <= $b ? 'active' : (($value < $a && $z > 0 && $value >= $a - $z) || ($value > $b && $zb > 0 && $value <= $b + $zb) ? 'attention' : 'inactive');
    }
}
