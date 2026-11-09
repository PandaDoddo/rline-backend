<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Domain\ComponentConstructionSchema;
use App\Modules\Equipment\Domain\ComponentDetailSchema;
use App\Modules\Equipment\Models\EquipmentComponent;
use Illuminate\Validation\ValidationException;

final class ComponentSpecifications
{
    public const RELATIONS = ['head_geometry' => 'headGeometry', 'fitting_geometry' => 'fittingGeometry', 'end_connection' => 'endConnection', 'boss_geometry' => 'bossGeometry', 'valve_details' => 'valveDetails', 'tube_geometry' => 'tubeGeometry', 'coil_geometry' => 'coilGeometry', 'lens_geometry' => 'lensGeometry', 'expansion_joint' => 'expansionJoint', 'shell_geometry' => 'shellGeometry'];

    public static function componentRelations(): array
    {
        return ['construction', ...array_values(self::RELATIONS), 'ports'];
    }

    public static function eagerLoads(bool $forWrite = false): array
    {
        return $forWrite ? [...array_map(fn ($relation) => 'components.'.$relation, self::componentRelations()),
            'components.connections.targets', 'components.connections.unresolvedTargets', 'components.assemblies.members', 'components' => fn ($query) => $query->withCount('historyEntries')] : ['components.cardProjection'];
    }

    public function read(EquipmentComponent $component): array
    {
        if ($component->relationLoaded('cardProjection')) {
            $projection = $component->getRelation('cardProjection');
            if (! $projection) {
                throw new \LogicException('Component card projection is missing.');
            }
            $result = ['construction' => $projection->getAttribute('construction'), 'ports' => $projection->getAttribute('ports')];
            foreach (self::RELATIONS as $block => $relation) {
                $result[$block] = $projection->getAttribute($block);
            }

            return $result;
        }
        foreach (self::componentRelations() as $relation) {
            if (! $component->relationLoaded($relation)) {
                throw new \LogicException('Component details must be batch-loaded before serialization.');
            }
        }
        $result = ['construction' => $component->construction?->only(array_keys(ComponentConstructionSchema::LABELS)),
            'ports' => $component->ports->map(fn ($port) => $port->only(['id', 'direction', ...ComponentPorts::FIELDS]))->all()];
        foreach (self::RELATIONS as $block => $relation) {
            $result[$block] = $component->getRelation($relation)?->only(array_keys(ComponentDetailSchema::BLOCKS[$block]));
        }

        return $result;
    }

    public function apply(EquipmentComponent $component, array $input): void
    {
        foreach (self::RELATIONS as $block => $relation) {
            $record = $component->getRelationValue($relation);
            $present = array_key_exists($block, $input);
            if (! $present && ! $record) {
                continue;
            }
            $fields = ComponentDetailSchema::BLOCKS[$block];
            $record ??= $component->{$relation}()->make();
            if ($present) {
                $record->fill($input[$block] ?? array_fill_keys(array_keys($fields), null));
            }
            $values = $record->only(array_keys($fields));
            $meaningful = array_filter($values, fn ($value) => $value !== null);
            if ($meaningful && ! ComponentDetailSchema::allowed($block, $component->kind, $component->type_code)) {
                throw ValidationException::withMessages(['structure' => 'Параметры не соответствуют типу компонента; очистите несовместимый блок явно.']);
            }
            foreach ($meaningful as $field => $value) {
                if ($fields[$field][2] !== null && ! in_array($component->type_code, $fields[$field][2], true)) {
                    throw ValidationException::withMessages(['structure' => 'Поле «'.$fields[$field][0].'» неприменимо к выбранному типу компонента.']);
                }
            }
            if ($present) {
                if ($meaningful) {
                    $record->save();
                } elseif ($record->exists) {
                    $record->delete();
                }
                $component->unsetRelation($relation);
            }
        }
    }
}
