<?php

namespace App\Modules\ProcessFlows\Application;

use App\Modules\Equipment\Domain\ComponentTree;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\ProcessFlows\Models\ProcessFlowNode;
use App\Modules\Shared\Domain\ValueEquality;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EquipmentFlowContacts
{
    public static function rules(): array
    {
        return ['flow_contacts' => 'sometimes|array|list|max:2000',
            'flow_contacts.*' => 'required|array:id,scope,component_id,side,mode,notes,verified,unlock,flows',
            'flow_contacts.*.id' => 'required|uuid|distinct', 'flow_contacts.*.scope' => 'required|in:equipment,tube,shell,component',
            'flow_contacts.*.component_id' => 'present|nullable|uuid', 'flow_contacts.*.side' => 'required|in:internal,external,heating,insulation',
            'flow_contacts.*.mode' => 'required|in:assign,none,inherit', 'flow_contacts.*.notes' => 'nullable|string|max:4000',
            'flow_contacts.*.verified' => 'sometimes|boolean', 'flow_contacts.*.unlock' => 'sometimes|boolean',
            'flow_contacts.*.flows' => 'present|array|list|max:100', 'flow_contacts.*.flows.*' => 'required|array:id,flow_id,share_percent,notes',
            'flow_contacts.*.flows.*.id' => 'required|uuid|distinct', 'flow_contacts.*.flows.*.flow_id' => 'required|uuid',
            'flow_contacts.*.flows.*.share_percent' => 'present|nullable|numeric|min:0|max:100', 'flow_contacts.*.flows.*.notes' => 'nullable|string|max:4000'];
    }

    public function apply(Equipment $equipment, array $input, int $actor): void
    {
        $db = DB::connection('tenant');
        $stored = $this->read($equipment)['contacts'];
        $old = collect($stored)->keyBy('id');
        $components = $equipment->components()->get()->keyBy('id');
        $tree = new ComponentTree($components->values()->toArray());
        if (array_sum(array_map(fn ($row) => count($row['flows']), $input)) > 5000) {
            $this->invalid('Не больше 5000 связей потоков за одно сохранение.');
        }
        $flowIds = collect($input)->pluck('flows')->flatten(1)->pluck('flow_id')->unique()->values()->all();
        // Lock in stable order: archiving and assignment cannot race with validation.
        $flows = ProcessFlowNode::whereIn('id', $flowIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $contactOwners = $db->table('equipment_flow_contacts')->whereIn('id', array_column($input, 'id'))->pluck('equipment_id', 'id');
        $linkOwners = $db->table('equipment_flow_links')->whereIn('id', collect($input)->pluck('flows')->flatten(1)->pluck('id'))->pluck('contact_id', 'id');
        $keys = [];
        foreach ($stored as $row) {
            $keys[$this->target($row)] = $row['id'];
        }
        foreach ($input as $row) {
            $existing = $old->get($row['id']);
            if (! $existing && $contactOwners->has($row['id'])) {
                $this->invalid('Назначение принадлежит другой ЕО.');
            }
            if (($row['scope'] === 'component') !== ($row['component_id'] !== null)) {
                $this->invalid('Выберите компонент только для области «компонент».');
            }
            if ($row['component_id'] && ! $components->has($row['component_id'])) {
                $this->invalid('Компонент принадлежит другой ЕО.');
            }
            if ($existing && ($this->target($existing) !== $this->target($row))) {
                $this->invalid('Область и сторона назначения неизменны; создайте новую запись.');
            }
            $key = $this->target($row);
            if (in_array($row['scope'], ['tube', 'shell'], true) && $row['side'] !== 'internal') {
                $this->invalid('Пространство задаёт внутреннюю среду; сторона пучка определяется отдельно.');
            }
            if (isset($keys[$key]) && $keys[$key] !== $row['id']) {
                $this->invalid('Для области и стороны уже есть запись. Измените её режим.');
            }
            $keys[$key] = $row['id'];
            if (count($keys) > 2000) {
                $this->invalid('Не больше 2000 областей контакта на ЕО.');
            }
            if ($row['mode'] === 'assign' && ! $row['flows'] || $row['mode'] !== 'assign' && $row['flows']) {
                $this->invalid('Назначение требует потоков; для наследования/отсутствия список должен быть пустым.');
            }
            if (count(array_unique(array_column($row['flows'], 'flow_id'))) !== count($row['flows'])) {
                $this->invalid('Поток повторяется в одной области.');
            }
            $clean = $this->editable($row);
            $changed = ! $existing || ! ValueEquality::same($this->editable($existing), $clean);
            if ($existing && $existing['check'] && $changed && ! ($row['unlock'] ?? false) && ($row['verified'] ?? true)) {
                $this->invalid('Сначала снимите проверку назначения.');
            }
            if ($changed && $row['component_id'] && ! $tree->active($row['component_id'])) {
                $this->invalid('Восстановите демонтированный компонент перед правкой назначения.');
            }
            $oldLinks = collect($existing['flows'] ?? [])->keyBy('id');
            $sum = 0;
            foreach ($row['flows'] as $link) {
                $flow = $flows->get($link['flow_id']);
                if (! $flow || $flow->installation_id !== $equipment->installation_id || $flow->kind !== 'flow') {
                    $this->invalid('Поток должен принадлежать установке оборудования.');
                }
                $previous = $oldLinks->get($link['id']);
                if (! $previous && $linkOwners->has($link['id'])) {
                    $this->invalid('Строка потока принадлежит другому назначению.');
                }
                if ($flow->status !== 'active' && (! $previous || $previous['flow_id'] !== $link['flow_id'])) {
                    $this->invalid('Нельзя назначить архивный поток.');
                }
                if ($row['side'] === 'internal' && $flow->category === 'external' || in_array($row['side'], ['external', 'insulation'], true) && $flow->category !== 'external') {
                    $this->invalid('Наружная и внутренняя среды назначаются на соответствующие стороны.');
                }
                if ($row['side'] === 'insulation' && $flow->medium_kind !== 'insulation') {
                    $this->invalid('Для изоляции выберите наружный поток вида «изоляция».');
                }
                if ($link['share_percent'] !== null && $flow->category !== 'alternative') {
                    $this->invalid('Доля времени задаётся только альтернативному потоку.');
                }
                if (($row['verified'] ?? false) && ($flow->status !== 'active' || $flow->category === 'alternative' && $link['share_percent'] === null)) {
                    $this->invalid('Для проверки восстановите поток и заполните долю альтернативного режима.');
                }
                $sum += $link['share_percent'] ?? 0;
            }
            if ($sum > 100 + 1e-9) {
                $this->invalid('Сумма долей альтернативных потоков превышает 100 %.');
            }
            $check = $changed || ($row['unlock'] ?? false) ? null : ($existing['check'] ?? null);
            if (array_key_exists('verified', $row)) {
                if ($row['verified'] && $row['mode'] === 'inherit') {
                    $this->invalid('Наследование не является проверенным назначением.');
                }
                $check = $row['verified'] ? ($check ?? ['by' => $actor, 'at' => now()->toIso8601String()]) : null;
            }
            $values = ['equipment_id' => $equipment->id, 'component_id' => $row['component_id'], 'scope' => $row['scope'], 'side' => $row['side'], 'mode' => $row['mode'], 'notes' => $row['notes'] ?? null, 'check' => $check === null ? null : json_encode($check), 'created_at' => $existing['created_at'] ?? now(), 'updated_at' => now()];
            // Never upsert an unowned UUID: concurrent inserts must fail uniquely, not transfer ownership.
            if ($existing) {
                $db->table('equipment_flow_contacts')->where('id', $row['id'])->where('equipment_id', $equipment->id)->update($values);
            } else {
                $db->table('equipment_flow_contacts')->insert(['id' => $row['id']] + $values);
            }
            $ids = array_column($row['flows'], 'id');
            $db->table('equipment_flow_links')->where('contact_id', $row['id'])->whereNotIn('id', $ids)->delete();
            foreach ($row['flows'] as $link) {
                $values = ['contact_id' => $row['id'], 'flow_id' => $link['flow_id'], 'share_percent' => $link['share_percent'], 'notes' => $link['notes'] ?? null, 'accepted_version' => $changed || ($row['verified'] ?? false) || ($row['unlock'] ?? false) ? $flows[$link['flow_id']]->version : ($oldLinks->get($link['id'])['accepted_version'] ?? $flows[$link['flow_id']]->version)];
                if ($oldLinks->has($link['id'])) {
                    $db->table('equipment_flow_links')->where('id', $link['id'])->where('contact_id', $row['id'])->update($values);
                } else {
                    $db->table('equipment_flow_links')->insert(['id' => $link['id']] + $values);
                }
            }
        }
        // Omitted contacts remain; a move to another installation must explicitly clear every old flow.
        $total = $db->table('equipment_flow_links as l')->join('equipment_flow_contacts as c', 'c.id', '=', 'l.contact_id')->where('c.equipment_id', $equipment->id)->count();
        if ($total > 5000) {
            $this->invalid('Не больше 5000 связей потоков на ЕО.');
        }
        $invalid = $db->table('equipment_flow_links as l')->join('equipment_flow_contacts as c', 'c.id', '=', 'l.contact_id')->join('process_flow_nodes as f', 'f.id', '=', 'l.flow_id')->where('c.equipment_id', $equipment->id)->where('f.installation_id', '<>', $equipment->installation_id)->exists();
        if ($invalid) {
            $this->invalid('Перед переносом ЕО снимите назначения потоков прежней установки.');
        }
    }

    public function read(Equipment $equipment): array
    {
        $db = DB::connection('tenant');
        $contacts = $db->table('equipment_flow_contacts')->where('equipment_id', $equipment->id)->orderBy('scope')->orderBy('component_id')->orderBy('side')->get();
        $links = $db->table('equipment_flow_links as l')->join('process_flow_nodes as f', 'f.id', '=', 'l.flow_id')->whereIn('l.contact_id', $contacts->pluck('id'))->select(['l.*', 'f.name as flow_name', 'f.category as flow_category', 'f.version as flow_version', 'f.status as flow_status'])->orderBy('f.category')->orderBy('f.name')->get()->groupBy('contact_id');
        $rows = $contacts->map(fn ($row) => ['id' => $row->id, 'scope' => $row->scope, 'component_id' => $row->component_id, 'side' => $row->side, 'mode' => $row->mode, 'notes' => $row->notes, 'check' => $row->check === null ? null : json_decode($row->check, true), 'created_at' => $row->created_at,
            'flows' => ($links->get($row->id) ?? collect())->map(fn ($link) => ['id' => $link->id, 'flow_id' => $link->flow_id, 'share_percent' => $link->share_percent === null ? null : (float) $link->share_percent, 'notes' => $link->notes, 'flow_name' => $link->flow_name, 'flow_category' => $link->flow_category, 'flow_version' => (int) $link->flow_version, 'accepted_version' => (int) $link->accepted_version, 'stale' => (int) $link->accepted_version !== (int) $link->flow_version, 'flow_status' => $link->flow_status])->all()])->all();

        return ['contacts' => $rows, 'effective' => $this->effective($equipment->components->toArray(), $rows)];
    }

    public function effective(array $components, array $contacts): array
    {
        $map = [];
        $shares = [];
        $tree = new ComponentTree($components);
        foreach ($contacts as $contact) {
            if ($contact['mode'] !== 'inherit') {
                $map[$this->target($contact)] = $contact;
            }
            $alternatives = array_values(array_filter($contact['flows'], fn ($flow) => $flow['flow_category'] === 'alternative'));
            $known = ! in_array(null, array_column($alternatives, 'share_percent'), true) && count(array_filter($contact['flows'], fn ($flow) => $flow['flow_category'] === 'main')) > 0;
            $shares[$contact['id']] = $known ? max(0, 100 - array_sum(array_column($alternatives, 'share_percent'))) : null;
        }
        $result = [];
        foreach ($components as $component) {
            $root = $tree->root($component['id']);
            foreach (['internal', 'external', 'heating', 'insulation'] as $side) {
                $targets = array_map(fn ($owner) => 'component:'.$owner['id'].':'.$side, $tree->lineage($component['id']));
                if (! $root['is_coil']) {
                    $space = $root['kind'] === 'tube_bundle' ? ($side === 'internal' ? 'tube' : ($side === 'external' ? 'shell' : null)) : ($side === 'internal' ? $tree->value($component['id'], 'flow_space') : null);
                    if ($space) {
                        $targets[] = $space.'::internal';
                    }
                    $targets[] = 'equipment::'.$side;
                }
                $found = null;
                foreach ($targets as $target) {
                    if (isset($map[$target])) {
                        $found = $map[$target];
                        break;
                    }
                }
                $active = $tree->active($component['id']);
                $result[] = ['component_id' => $component['id'], 'side' => $side, 'active' => $active, 'contact_id' => $found['id'] ?? null, 'inherited' => $found !== null && $found['component_id'] !== $component['id'], 'mode' => $found['mode'] ?? 'unknown', 'main_share_percent' => $found ? $shares[$found['id']] : null];
            }
        }

        return $result;
    }

    private function target(array $row): string
    {
        return $row['scope'].':'.($row['component_id'] ?? '').':'.$row['side'];
    }

    private function editable(array $row): array
    {
        $links = array_map(fn ($link) => ['id' => $link['id'], 'flow_id' => $link['flow_id'], 'share_percent' => $link['share_percent'], 'notes' => $link['notes'] ?? null], $row['flows']);
        usort($links, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return ['mode' => $row['mode'], 'notes' => $row['notes'] ?? null, 'flows' => $links];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['flow_contacts' => $message]);
    }
}
