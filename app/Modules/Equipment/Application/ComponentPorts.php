<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Models\EquipmentComponent;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class ComponentPorts
{
    public const FIELDS = ['mark', 'service_name', 'connection_kind'];

    public function apply(EquipmentComponent $component, array $ports): void
    {
        if ($ports && (! $component->is_coil || $component->parent_id !== null)) {
            throw ValidationException::withMessages(['structure' => 'Вход/выход задаются для самостоятельного змеевика.']);
        }
        $directions = array_column($ports, 'direction');
        if (count($directions) !== count(array_unique($directions))) {
            throw ValidationException::withMessages(['structure' => 'Направление входа/выхода не должно повторяться у одного змеевика.']);
        }
        $existing = $component->ports->keyBy('direction');
        foreach ($ports as $input) {
            $port = $existing->get($input['direction']) ?? $component->ports()->make();
            $port->fill($input);
            if (collect(self::FIELDS)->contains(fn ($field) => $port->getAttribute($field) !== null)) {
                if (! $port->exists) {
                    $port->forceFill(['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'rline:component-port:'.$component->id.':'.$input['direction'])->toString(), 'direction' => $input['direction']]);
                }
                $port->save();
            } elseif ($port->exists) {
                $port->delete();
            }
        }
        $component->unsetRelation('ports');
    }

    public static function rules(): array
    {
        $rules = ['structure.*.ports' => 'sometimes|array|list|max:2', 'structure.*.ports.*' => 'required|array:direction,'.implode(',', self::FIELDS), 'structure.*.ports.*.direction' => 'required|in:inlet,outlet'];
        foreach (self::FIELDS as $field) {
            $rules['structure.*.ports.*.'.$field] = 'sometimes|nullable|string|max:255';
        }

        return $rules;
    }
}
