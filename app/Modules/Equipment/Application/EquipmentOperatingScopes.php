<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Domain\OperatingScopeSchema;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Equipment\Models\EquipmentOperatingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class EquipmentOperatingScopes
{
    public function read(Equipment $equipment): array
    {
        $equipment->loadMissing(['components', 'operatingScopes.annotations']);
        $stored = $equipment->operatingScopes->keyBy(fn ($scope) => $this->key($scope->kind, $scope->component_id));
        $result = [];
        if ($equipment->profile_code === 'ves_teplo' || isset($stored['tube:'])) {
            $result[] = $this->present($equipment, 'tube', null, 'Трубное пространство', $equipment->profile_code === 'ves_teplo', $stored['tube:'] ?? null);
        }
        foreach ($equipment->components as $component) {
            if ($component->is_coil && $component->parent_id === null) {
                $result[] = $this->present($equipment, 'coil', $component->id, $component->code.' · '.$component->name, $component->status === 'active', $stored[$this->key('coil', $component->id)] ?? null);
            }
        }

        return $result;
    }

    public function apply(Equipment $equipment, array $patches, int $actor): void
    {
        if (DB::connection('tenant')->transactionLevel() === 0) {
            throw new \LogicException('Operating scopes require an aggregate transaction.');
        }
        $equipment->loadMissing('components');
        $components = $equipment->components->keyBy('id');
        $stored = EquipmentOperatingScope::where('equipment_id', $equipment->id)->with('annotations')->lockForUpdate()->get()->keyBy(fn ($scope) => $this->key($scope->kind, $scope->component_id));
        $seen = [];
        foreach ($patches as $index => $patch) {
            $componentId = isset($patch['component_id']) ? strtolower($patch['component_id']) : null;
            $kind = $patch['kind'];
            $key = $this->key($kind, $componentId);
            $path = 'operating_scopes.'.$index;
            if (isset($seen[$key])) {
                $this->invalid($path, 'Область параметров передана повторно.');
            }$seen[$key] = true;
            if ($kind === 'tube' && ($componentId !== null || $equipment->profile_code !== 'ves_teplo')) {
                $this->invalid($path, 'Трубное пространство доступно только у теплообменника.');
            }
            if ($kind === 'coil') {
                $component = $components[$componentId] ?? null;
                abort_unless($component !== null, 404, 'Компонент другой ЕО или не найден.');
                if (! $component->is_coil || $component->parent_id !== null || $component->status !== 'active') {
                    $this->invalid($path, 'Укажите действующий самостоятельный змеевик.');
                }
            }
            $scope = $stored[$key] ?? null;
            [$values,$qualifiers,$checks] = $this->extract($scope);
            $before = [$values, $qualifiers];
            foreach (['values', 'qualifiers', 'verification'] as $part) {
                foreach ($patch[$part] ?? [] as $field => $value) {
                    if (! in_array($kind, OperatingScopeSchema::FIELDS[$field], true)) {
                        $this->invalid($path.'.'.$part.'.'.$field, 'Параметр не относится к выбранной области.');
                    }
                }
            }
            foreach ($patch['values'] ?? [] as $field => $value) {
                $values[$field] = $value;
                if ($value !== null && ! array_key_exists($field, $patch['qualifiers'] ?? [])) {
                    unset($qualifiers[$field]);
                }
            }
            foreach ($patch['qualifiers'] ?? [] as $field => $qualifier) {
                if ($qualifier === null) {
                    unset($qualifiers[$field]);
                } else {
                    if (! isset($qualifier['state']) && ! isset($qualifier['precision'])) {
                        $this->invalid($path.'.qualifiers.'.$field, 'Укажите состояние или точность даты.');
                    }$qualifiers[$field] = $qualifier;
                }
            }
            foreach ($qualifiers as $field => $qualifier) {
                if (($values[$field] ?? null) !== null && isset($qualifier['state'])) {
                    $this->invalid($path.'.qualifiers.'.$field, 'Состояние поля требует пустого значения.');
                }
                if (($qualifier['precision'] ?? null) === 'year' && isset($values[$field]) && ! str_ends_with($values[$field], '-01-01')) {
                    $this->invalid($path.'.qualifiers.'.$field, 'При известном только годе дата хранится как 01.01 указанного года.');
                }
            }
            foreach (array_unique([...array_keys($before[0]), ...array_keys($values), ...array_keys($before[1]), ...array_keys($qualifiers)]) as $field) {
                $a = $before[0][$field] ?? null;
                $b = $values[$field] ?? null;
                $same = $a === $b || (is_int($a) || is_float($a)) && (is_int($b) || is_float($b)) && (float) $a === (float) $b;
                if (! $same || ($before[1][$field] ?? null) !== ($qualifiers[$field] ?? null)) {
                    unset($checks[$field]);
                }
            }
            foreach ($patch['verification'] ?? [] as $field => $verified) {
                if (! $verified) {
                    unset($checks[$field]);

                    continue;
                }
                if (($values[$field] ?? null) === null && ! isset($qualifiers[$field]['state'])) {
                    $this->invalid($path.'.verification.'.$field, 'Заполните значение или укажите его состояние.');
                }
                $checks[$field] ??= ['by' => $actor, 'at' => now()->toIso8601String()];
            }
            if ($scope === null && ! array_filter($values, fn ($value) => $value !== null) && ! $qualifiers && ! $checks) {
                continue;
            }
            $scope ??= (new EquipmentOperatingScope)->forceFill(['id' => $this->id($equipment->id, $kind, $componentId), 'equipment_id' => $equipment->id, 'component_id' => $componentId, 'kind' => $kind, 'created_by' => $actor]);
            $scope->forceFill(array_replace(array_fill_keys(array_keys(OperatingScopeSchema::FIELDS), null), $values, ['updated_by' => $actor]))->save();
            $annotations = [];
            foreach (array_unique([...array_keys($values), ...array_keys($qualifiers), ...array_keys($checks)]) as $field) {
                $annotations[] = ['scope_id' => $scope->id, 'field_key' => $field, 'value_present' => array_key_exists($field, $values), 'state' => $qualifiers[$field]['state'] ?? null, 'precision' => $qualifiers[$field]['precision'] ?? null, 'verified_by' => $checks[$field]['by'] ?? null, 'verified_at' => $checks[$field]['at'] ?? null];
            }
            $db = DB::connection('tenant');
            $db->table('equipment_operating_scope_annotations')->where('scope_id', $scope->id)->delete();
            if ($annotations) {
                $db->table('equipment_operating_scope_annotations')->insert($annotations);
            }
        }
        $equipment->unsetRelation('operatingScopes');
    }

    private function extract(?EquipmentOperatingScope $scope): array
    {
        $values = [];
        $qualifiers = [];
        $checks = [];
        foreach ($scope?->annotations ?? [] as $annotation) {
            $key = $annotation->field_key;
            if ($annotation->value_present) {
                $values[$key] = $scope->getAttribute($key);
            }if ($annotation->state !== null) {
                $qualifiers[$key] = ['state' => $annotation->state];
            }if ($annotation->precision !== null) {
                $qualifiers[$key]['precision'] = $annotation->precision;
            }if ($annotation->verified_at !== null) {
                $checks[$key] = ['by' => (int) $annotation->verified_by, 'at' => $annotation->verified_at->toIso8601String()];
            }
        }

        return [$values, $qualifiers, $checks];
    }

    private function present(Equipment $equipment, string $kind, ?string $component, string $name, bool $active, ?EquipmentOperatingScope $scope): array
    {
        [$values,$qualifiers,$checks] = $this->extract($scope);

        return ['id' => $this->id($equipment->id, $kind, $component), 'kind' => $kind, 'component_id' => $component, 'name' => $name, 'active' => $active, 'persisted' => $scope !== null, 'values' => (object) $values, 'qualifiers' => (object) $qualifiers, 'checks' => (object) $checks];
    }

    private function key(string $kind, ?string $component): string
    {
        return $kind.':'.($component ?? '');
    }

    private function id(string $equipment, string $kind, ?string $component): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'rline:operating-scope:'.$equipment.':'.$this->key($kind, $component))->toString();
    }

    private function invalid(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }

    public static function rules(): array
    {
        $keys = implode(',', array_keys(OperatingScopeSchema::FIELDS));
        $rules = ['operating_scopes' => 'sometimes|array|list|max:100', 'operating_scopes.*' => 'required|array:kind,component_id,values,qualifiers,verification', 'operating_scopes.*.kind' => 'required|in:tube,coil', 'operating_scopes.*.component_id' => 'present|nullable|uuid',
            'operating_scopes.*.values' => 'sometimes|array:'.$keys, 'operating_scopes.*.qualifiers' => 'sometimes|array:'.$keys, 'operating_scopes.*.verification' => 'sometimes|array:'.$keys];
        foreach (OperatingScopeSchema::fields() as $key => $field) {
            $rules['operating_scopes.*.values.'.$key] = 'sometimes|'.$field['rules'];
            $rules['operating_scopes.*.qualifiers.'.$key] = 'sometimes|nullable|array:state,precision';
            $date = ($field['type'] ?? null) === 'date';
            $rules['operating_scopes.*.qualifiers.'.$key.'.state'] = ($date ? 'sometimes' : 'required_with:operating_scopes.*.qualifiers.'.$key).'|in:unknown,not_applicable,not_present';
            $rules['operating_scopes.*.qualifiers.'.$key.'.precision'] = $date ? 'sometimes|in:day,year' : 'prohibited';
            $rules['operating_scopes.*.verification.'.$key] = 'sometimes|boolean';
        }

        return $rules;
    }
}
