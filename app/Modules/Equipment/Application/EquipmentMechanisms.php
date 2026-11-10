<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Diagnostics\Application\EquipmentVisualInspections;
use App\Modules\Diagnostics\Domain\VisualInspectionAssessment;
use App\Modules\Equipment\Domain\ComponentTree;
use App\Modules\Equipment\Domain\Screening\ComponentMechanismScreening;
use App\Modules\Equipment\Domain\Screening\ComponentScreeningSchema;
use App\Modules\Equipment\Infrastructure\MechanismFacts;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\ProcessFlows\Application\EquipmentFlowContacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The equipment transaction owns facts; derived states and review authors never come from clients. */
final class EquipmentMechanisms
{
    public function __construct(private readonly MechanismFacts $facts, private readonly MechanismProjection $projection,
        private readonly MechanismContextProvider $contexts, private readonly ComponentMechanismScreening $engine) {}

    public function read(Equipment $equipment, bool $lock = false): array
    {
        [$flows, $scopes, $visual] = $this->sources($equipment);

        return $this->projection->read($equipment, $flows, $scopes, $visual, $lock);
    }

    public function detail(Equipment $equipment, string $component): array
    {
        [$flows, $scopes, $visual] = $this->sources($equipment);
        $visual['elements'] = array_values(array_filter($visual['elements'], fn ($row) => $row['component_id'] === $component));
        $facts = $this->facts->read($equipment->id);
        $projection = $this->projection->read($equipment, $flows, $scopes, $visual, false, $facts);
        $element = $projection['elements'][0] ?? null;
        abort_unless($element, 404, 'Физический элемент не найден в этой ЕО.');
        if (! $element['active']) {
            return $element;
        }
        $overrides = [];
        foreach ($element['mechanisms'] as $method) {
            foreach ($method['sides'] as $side) {
                if ($side['override'] !== null) {
                    $overrides[$method['id'].'|'.$side['side']] = $side['override'];
                }
            }
        }
        $factory = $this->contexts->factory($equipment, $flows, $scopes, $visual, $facts);
        $context = $factory->element($component, config('equipment.profiles')[$equipment->profile_code]['g'] === 'pipe');
        $result = $this->engine->evaluate($context['context'], $context['internal'], $context['external'], $overrides);

        return [...$element, 'mechanisms' => $result['mechanisms']];
    }

    private function sources(Equipment $equipment): array
    {
        $flows = app(EquipmentFlowContacts::class)->read($equipment);
        $scopes = app(EquipmentOperatingScopes::class)->read($equipment);
        $visual = app(EquipmentVisualInspections::class)->read($equipment);
        $visual = array_replace($visual, app(VisualInspectionAssessment::class)->evaluate($equipment->components->toArray(), $visual, $flows, $equipment->passport, $scopes));

        return [$flows, $scopes, $visual];
    }

    public function apply(Equipment $equipment, array $input, int $actor): void
    {
        if ($input['schema_version'] !== ComponentScreeningSchema::VERSION) {
            $this->invalid('schema_version', 'Версия правил МД изменилась. Обновите карточку.');
        }
        $this->unique($input['overrides'] ?? [], ['component_id', 'mechanism_code', 'side'], 'overrides');
        $this->unique($input['question_notes'] ?? [], ['component_id', 'question_id', 'side', 'flow_id'], 'question_notes');
        $components = array_column($equipment->components->toArray(), null, 'id');
        $tree = new ComponentTree(array_values($components));
        foreach (['component_inputs', 'overrides', 'question_notes', 'reviews'] as $section) {
            foreach ($input[$section] ?? [] as $index => $entry) {
                if (! isset($components[$entry['component_id']])) {
                    $this->invalid("$section.$index.component_id", 'Компонент не принадлежит этой ЕО.');
                }
                $clearing = match ($section) {
                    'overrides' => $entry['state'] === null,
                    'question_notes' => $entry['note'] === null,
                    'reviews' => ! $entry['verified'],
                    default => ! array_filter(array_intersect_key($entry, array_flip(MechanismFacts::COMPONENT_FIELDS)), fn ($value) => $value !== null),
                };
                if (! $clearing && ! $tree->active($entry['component_id'])) {
                    $this->invalid("$section.$index.component_id", 'Для архивного компонента можно только сбросить ранее сохранённые факты.');
                }
            }
        }
        // Clearing approvals is explicit, and can accompany an override in this same atomic command.
        foreach ($input['reviews'] ?? [] as $entry) {
            if (! $entry['verified']) {
                $this->facts->review($equipment->id, $entry['component_id'], null, $actor);
            }
        }
        $protected = array_column(array_filter($this->read($equipment, true)['elements'], fn ($row) => $row['review']['verified']), 'input_signature', 'component_id');
        if (isset($input['inputs'])) {
            $this->facts->root($equipment->id, $input['inputs'], $actor);
        }
        foreach ($input['component_inputs'] ?? [] as $entry) {
            $this->facts->component($equipment->id, $entry['component_id'], $entry, $actor);
        }
        [$flows, $scopes, $visual] = $this->sources($equipment);
        $facts = $this->facts->read($equipment->id);
        $current = $this->projection->read($equipment, $flows, $scopes, $visual, true, $facts);
        $elements = array_column($current['elements'], null, 'component_id');
        $factory = $this->contexts->factory($equipment, $flows, $scopes, $visual, $facts, true);
        $pipeline = config('equipment.profiles')[$equipment->profile_code]['g'] === 'pipe';
        $traces = [];
        foreach ($input['overrides'] ?? [] as $index => $entry) {
            $element = $elements[$entry['component_id']] ?? null;
            if ($entry['state'] !== null) {
                if (! ($element['active'] ?? false)) {
                    $this->invalid("overrides.$index.component_id", 'Выберите действующий физический элемент.');
                }
                $method = array_column($element['mechanisms'], null, 'id')[$entry['mechanism_code']] ?? null;
                if (! in_array($entry['side'], array_column($method['sides'] ?? [], 'side'), true)) {
                    $this->invalid("overrides.$index.side", 'Эта сторона не применяется к механизму и текущему контакту.');
                }
                if (trim($entry['reason'] ?? '') === '') {
                    $this->invalid("overrides.$index.reason", 'Укажите обоснование ручного статуса.');
                }
                abort_unless(($entry['source_signature'] ?? null) === $element['source_signature'], 409, 'Исходные данные МД изменились. Обновите результаты перед ручной правкой.');
            }
            if ($element['review']['verified'] ?? false) {
                $this->invalid("overrides.$index", 'Сначала снимите подтверждение МД элемента.');
            }
            $this->facts->override($equipment->id, $entry, $actor);
        }
        foreach ($input['question_notes'] ?? [] as $index => $entry) {
            if ($entry['note'] !== null) {
                if (! ($elements[$entry['component_id']]['active'] ?? false)) {
                    $this->invalid("question_notes.$index.component_id", 'Выберите действующий физический элемент.');
                }
                $context = $factory->element($entry['component_id'], $pipeline);
                $trace = $traces[$context['key']] ??= $this->engine->evaluate($context['context'], $context['internal'], $context['external']);
                $valid = false;
                foreach ($trace['mechanisms'] as $method) {
                    foreach ($method['sides'] as $side) {
                        if ($side['side'] !== $entry['side']) {
                            continue;
                        }
                        foreach ($side['runs'] as $run) {
                            if ($run['flow_id'] === $entry['flow_id'] && in_array($entry['question_id'], array_column($run['questions'], 'id'), true)) {
                                $valid = true;
                            }
                        }
                    }
                }
                if (! $valid) {
                    $this->invalid("question_notes.$index", 'Вопрос, сторона и поток должны соответствовать текущему контексту элемента.');
                }
            }
            $this->facts->note($equipment->id, $entry, $actor);
        }
        $this->guardInstallation($equipment);
        if ($protected || array_filter($input['reviews'] ?? [], fn ($entry) => $entry['verified'])) {
            $final = $this->projection->read($equipment, $flows, $scopes, $visual, true);
            $finalElements = array_column($final['elements'], null, 'component_id');
            foreach ($protected as $component => $signature) {
                if (($finalElements[$component]['input_signature'] ?? null) !== $signature) {
                    $this->invalid('reviews', 'Сначала снимите подтверждение МД затронутого элемента, затем меняйте его входы и комментарии.');
                }
            }
            foreach ($input['reviews'] ?? [] as $index => $entry) {
                if (! $entry['verified']) {
                    continue;
                }
                $element = $finalElements[$entry['component_id']] ?? null;
                if (! ($element['active'] ?? false)) {
                    $this->invalid("reviews.$index.component_id", 'Подтверждение доступно только для действующего физического элемента.');
                }
                abort_unless(($entry['input_signature'] ?? null) === $element['input_signature'], 409, 'Данные МД изменились. Сначала сохраните входы и проверьте обновлённый результат.');
                // An unchanged confirmation keeps the original author and time.
                if (! $element['review']['verified']) {
                    $this->facts->review($equipment->id, $entry['component_id'], $element['input_signature'], $actor);
                }
            }
        }
    }

    public function guardInstallation(Equipment $equipment): void
    {
        $foreign = DB::connection('tenant')->table('equipment_mechanism_question_notes as n')->join('process_flow_nodes as f', 'f.id', '=', 'n.flow_id')
            ->where('n.equipment_id', $equipment->id)->where('f.installation_id', '!=', $equipment->installation_id)->exists();
        if ($foreign) {
            $this->invalid('question_notes', 'Перед переносом ЕО очистите комментарии МД, привязанные к потокам прежней установки.');
        }
    }

    private function unique(array $rows, array $keys, string $section): void
    {
        $seen = [];
        foreach ($rows as $index => $row) {
            $key = json_encode(array_map(fn ($field) => $row[$field], $keys), JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                $this->invalid("$section.$index", 'Координаты записи МД повторяются.');
            }
            $seen[$key] = true;
        }
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages(['mechanisms.'.$key => $message]);
    }
}
