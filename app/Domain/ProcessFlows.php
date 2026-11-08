<?php

namespace App\Domain;

use App\Models\Installation;
use App\Models\ProcessFlowCalculation;
use App\Models\ProcessFlowComposition;
use App\Models\ProcessFlowNode;
use App\Models\Substance;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class ProcessFlows
{
    public function __construct(private TenantWrites $writes, private FlowHierarchy $hierarchy, private FlowCompositionResolver $resolver, private MixtureCalculator $mixtures) {}

    public function initialize(string $id, array $data, int $actor): Installation
    {
        return $this->writes->transaction(function () use ($id, $data, $actor) {
            $installation = Installation::whereKey($id)->lockForUpdate()->firstOrFail();
            $answers = [];
            foreach (config('process-flows.categories') as $key => $label) {
                $answers[$key] = ['enabled' => $data['answers'][$key]['enabled'] === null ? null : (bool) $data['answers'][$key]['enabled'], 'comment' => $data['answers'][$key]['comment']];
            }
            if ($installation->flow_setup) {
                abort_unless(strtolower($installation->flow_setup['request_id']) === strtolower($data['request_id']) && CatalogValues::same($installation->flow_setup['answers'], $answers), 409, 'Первичное наполнение уже выполнено. Используйте правку дерева.');

                return $installation;
            }
            $this->structureVersion($installation, $data['structure_version']);
            abort_if(ProcessFlowNode::where('installation_id', $id)->exists(), 409, 'Дерево уже содержит записи; первичное наполнение не применяется.');
            foreach ($answers as $category => $answer) {
                if ($answer['enabled'] !== true) {
                    continue;
                }
                (new ProcessFlowNode)->forceFill(['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'rline:flow-root:'.$id.':'.$category)->toString(), 'installation_id' => $id, 'kind' => 'folder', 'category' => $category, 'name' => config('process-flows.categories.'.$category), 'created_by' => $actor, 'updated_by' => $actor])->save();
            }
            $installation->forceFill(['flow_setup' => ['request_id' => strtolower($data['request_id']), 'answers' => $answers, 'by' => $actor, 'at' => now()->toIso8601String()], 'flow_structure_version' => $installation->flow_structure_version + 1])->save();
            $this->writes->event('flow_structure', $installation, 'initialized', $actor, null, ['setup' => $installation->flow_setup]);

            return $installation;
        });
    }

    public function createNode(string $installationId, array $data, int $actor): ProcessFlowNode
    {
        return $this->writes->transaction(function () use ($installationId, $data, $actor) {
            $installation = Installation::whereKey($installationId)->lockForUpdate()->firstOrFail();
            $this->structureVersion($installation, $data['structure_version']);
            $this->hierarchy->parent($installationId, $data['parent_id'] ?? null, $data['category']);
            if ($data['category'] !== 'external' && ($data['medium_kind'] ?? 'fluid') !== 'fluid') {
                $this->invalid('medium_kind', 'Грунт, атмосфера и изоляция относятся к наружным средам.');
            }
            $node = (new ProcessFlowNode)->forceFill(['installation_id' => $installationId, 'name' => $data['name'], 'kind' => $data['kind'], 'category' => $data['category'], 'parent_id' => $data['parent_id'] ?? null, 'medium_kind' => $data['medium_kind'] ?? 'fluid', 'created_by' => $actor, 'updated_by' => $actor]);
            $node->save();
            $installation->forceFill(['flow_structure_version' => $installation->flow_structure_version + 1])->save();
            $this->writes->event('process_flow', $node, 'created', $actor, null, $this->snapshot($node->toArray()));

            return $node->refresh();
        });
    }

    public function updateNode(string $id, array $data, int $actor): ProcessFlowNode
    {
        $installationId = ProcessFlowNode::findOrFail($id)->installation_id;

        return $this->writes->transaction(function () use ($id, $data, $actor, $installationId) {
            $installation = Installation::whereKey($installationId)->lockForUpdate()->firstOrFail();
            $this->structureVersion($installation, $data['structure_version']);
            $node = ProcessFlowNode::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->writes->version($node, $data['version']);
            $this->hierarchy->parent($installationId, array_key_exists('parent_id', $data) ? $data['parent_id'] : $node->parent_id, $node->category, $id);
            if (($data['status'] ?? null) === 'archived' && ProcessFlowNode::where('parent_id', $id)->where('status', 'active')->exists()) {
                $this->invalid('status', 'Сначала архивируйте дочерние записи.');
            }
            $before = $node->toArray();
            foreach (['name', 'parent_id', 'status'] as $key) {
                if (array_key_exists($key, $data)) {
                    $node->setAttribute($key, $data[$key]);
                }
            }
            $node->forceFill(['version' => $node->version + 1, 'updated_by' => $actor])->save();
            $installation->forceFill(['flow_structure_version' => $installation->flow_structure_version + 1])->save();
            $this->writes->event('process_flow', $node, 'structure_updated', $actor, $this->snapshot($before), $this->snapshot($node->toArray()));

            return $node;
        });
    }

    public function updateCard(string $id, array $data, int $actor): ProcessFlowNode
    {
        return $this->writes->transaction(function () use ($id, $data, $actor) {
            $flow = ProcessFlowNode::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($flow->kind === 'flow', 422, 'У группы нет карточки среды.');
            abort_unless($flow->status === 'active', 422, 'Сначала восстановите поток из архива.');
            $this->writes->version($flow, $data['version']);
            $before = $flow->toArray();
            $compositionIds = [...array_column($data['composition'] ?? [], 'id'), ...($data['delete_composition_ids'] ?? [])];
            $before['composition'] = ProcessFlowComposition::where('flow_id', $id)->whereIn('id', $compositionIds)->get()->keyBy('id')->toArray();
            $attributes = $flow->attributes ?? [];
            $sources = $flow->sources ?? [];
            $checks = $flow->checks ?? [];
            $stamp = ['by' => $actor, 'at' => now()->toIso8601String()];
            foreach ($data['attributes'] ?? [] as $key => $value) {
                if (! CatalogValues::same($attributes[$key] ?? null, $value)) {
                    unset($checks[$key]);
                    $sources[$key] = ['citation' => null, 'conditions' => null] + $stamp;
                }
                $attributes[$key] = $value;
            }
            foreach ($data['sources'] ?? [] as $key => $source) {
                $sources[$key] = ['citation' => $source['citation'] ?? null, 'conditions' => $source['conditions'] ?? null] + $stamp;
                unset($checks[$key]);
            }
            foreach ($data['verification'] ?? [] as $key => $checked) {
                if (! $checked) {
                    unset($checks[$key]);
                } elseif (($attributes[$key] ?? null) === null) {
                    $this->invalid('verification.'.$key, 'Нельзя проверить незаполненное свойство.');
                } else {
                    $checks[$key] ??= $stamp;
                }
            }
            if (($data['basis'] ?? $flow->basis) !== $flow->basis && ProcessFlowComposition::where('flow_id', $id)->where('role', 'main')->exists()) {
                // Switching units must explicitly supply every existing main amount; old numbers are never relabelled.
                $requiredIds = ProcessFlowComposition::where('flow_id', $id)->where('role', 'main')->pluck('id')->all();
                $provided = array_column(array_filter($data['composition'] ?? [], fn ($row) => array_key_exists('amount', $row)), 'id');
                if (array_diff($requiredIds, [...$provided, ...($data['delete_composition_ids'] ?? [])])) {
                    $this->invalid('basis', 'При смене базы явно передайте новые значения всех основных компонентов.');
                }
            }
            $this->composition($flow, $data, $actor);
            $flow->forceFill(['attributes' => $attributes, 'sources' => $sources, 'checks' => $checks, 'basis' => $data['basis'] ?? $flow->basis, 'version' => $flow->version + 1, 'updated_by' => $actor]);
            if (($data['apply_calculation'] ?? false) || isset($data['expected_calculation_signature'])) {
                $inputs = $this->resolver->resolve($flow);
                $result = $this->mixtures->calculate($flow->basis, $inputs);
                abort_if(isset($data['expected_calculation_signature']) && $data['expected_calculation_signature'] !== $result['signature'], 409, 'Исходные свойства или состав изменились. Обновите поток и повторно примените расчётные свойства.');
            }
            if ($data['apply_calculation'] ?? false) {
                if (! $result['complete']) {
                    $this->invalid('apply_calculation', 'Для принятия расчёта нужен полный состав, сумма 100 % и MW всех компонентов.');
                }
                $calculation = (new ProcessFlowCalculation)->forceFill(['id' => (string) Str::uuid(), 'flow_id' => $id, 'signature' => $result['signature'], 'engine_version' => MixtureCalculator::VERSION, 'inputs' => ['basis' => $flow->basis, 'components' => $inputs], 'result' => $result, 'created_by' => $actor, 'created_at' => now()]);
                $calculation->save();
                $flow->accepted_calculation = ['id' => $calculation->id, 'signature' => $result['signature'], 'by' => $actor, 'at' => $calculation->created_at->toIso8601String(), 'properties' => $result['properties']];
            }
            $flow->save();
            $after = $flow->toArray();
            $after['composition'] = ProcessFlowComposition::where('flow_id', $id)->whereIn('id', $compositionIds)->get()->keyBy('id')->toArray();
            $this->writes->event('process_flow', $flow, 'card_updated', $actor, $this->snapshot($before), $this->snapshot($after));

            return $flow;
        });
    }

    private function composition(ProcessFlowNode $flow, array $data, int $actor): void
    {
        $rows = $data['composition'] ?? [];
        $deletes = $data['delete_composition_ids'] ?? [];
        if (array_intersect(array_column($rows, 'id'), $deletes)) {
            $this->invalid('composition', 'Компонент нельзя изменять и удалять одновременно.');
        }
        $existing = ProcessFlowComposition::whereIn('id', [...array_column($rows, 'id'), ...$deletes])->get()->keyBy('id');
        foreach ($existing as $line) {
            abort_unless($line->flow_id === $flow->id, 404, 'Компонент другого потока недоступен.');
        }
        foreach ($deletes as $id) {
            $line = $existing->get($id);
            if (! $line) {
                abort(404);
            } $line->delete();
        }
        foreach ($rows as $index => $row) {
            $line = $existing->get($row['id']);
            $values = array_replace($line?->only(['substance_id', 'role', 'name', 'amount', 'unit', 'plus', 'notes', 'position']) ?? ['role' => $row['role'], 'plus' => false, 'position' => $index], $row);
            if ($line && $line->role !== $row['role']) {
                $this->invalid('composition.'.$index.'.role', 'Роль строки неизменяема; создайте другую строку.');
            }
            if ($values['role'] === 'main' && empty($values['substance_id'])) {
                $this->invalid('composition.'.$index.'.substance_id', 'Основной компонент выбирается из каталога веществ.');
            }
            if ($values['role'] === 'main' && (! empty($values['name']) || ! empty($values['unit']))) {
                $this->invalid('composition.'.$index, 'Наименование берётся из каталога, единица состава задаётся общей массовой/мольной базой.');
            }
            if ($flow->medium_kind !== 'fluid' && $values['role'] === 'main') {
                $this->invalid('composition', 'Для грунта/атмосферы/изоляции основной химический состав не задаётся.');
            }
            if ($values['role'] === 'main' && isset($values['amount']) && $values['amount'] > 100) {
                $this->invalid('composition.'.$index.'.amount', 'Доля основного компонента не может превышать 100 %.');
            }
            if (! empty($values['substance_id'])) {
                $substance = Substance::findOrFail($values['substance_id']);
                if ($substance->status !== 'active' && (! $line || $line->substance_id !== $substance->id)) {
                    $this->invalid('composition.'.$index.'.substance_id', 'Архивное вещество нельзя выбрать заново.');
                }
            } elseif (trim($values['name'] ?? '') === '') {
                $this->invalid('composition.'.$index.'.name', 'Укажите наименование справочной строки или примеси.');
            }
            $line ??= (new ProcessFlowComposition)->forceFill(['id' => $row['id'], 'flow_id' => $flow->id]);
            unset($values['id']);
            $line->fill($values)->save();
        }
        $duplicate = ProcessFlowComposition::where('flow_id', $flow->id)->where('role', 'main')->groupBy('substance_id')->havingRaw('count(*) > 1')->selectRaw('1')->exists();
        if ($duplicate) {
            $this->invalid('composition', 'Вещество не должно повторяться в основном составе.');
        }
        if (ProcessFlowComposition::where('flow_id', $flow->id)->count() > 300) {
            $this->invalid('composition', 'В составе одного потока допускается не более 300 строк.');
        }
        $flow->unsetRelation('composition');
    }

    private function structureVersion(Installation $installation, int $expected): void
    {
        abort_if($installation->flow_structure_version !== $expected, 409, 'Структура потоков изменена. Обновите дерево; черновик не сохранён.');
    }

    private function snapshot(array $record): array
    {
        return ['overrides' => ($record['attributes'] ?? []) + ['name' => $record['name'], 'parent_id' => $record['parent_id'] ?? null, 'basis' => $record['basis'], 'accepted_calculation' => $record['accepted_calculation']['id'] ?? null, 'composition' => $record['composition'] ?? []],
            'status' => $record['status'], 'sources' => $record['sources'] ?? [], 'checks' => $record['checks'] ?? []];
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
