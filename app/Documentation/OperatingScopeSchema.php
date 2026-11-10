<?php

namespace App\Documentation;

use App\Modules\Equipment\Domain\OperatingScopeSchema as ScopeFields;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class OperatingScopeSchema
{
    public static function make(bool $input = false): ArrayType
    {
        $values = new ObjectType;
        $qualifiers = new ObjectType;
        $checks = new ObjectType;
        foreach (ScopeFields::fields() as $key => $field) {
            $property = ($field['type'] ?? null) === 'number' ? new NumberType : new StringType;
            $property->nullable(true)->setDescription($field['label']);
            if (($field['type'] ?? null) === 'date') {
                $property->format('date');
            }
            if (isset($field['options'])) {
                $property->enum($field['options']);
            }
            if ($property instanceof NumberType) {
                if (preg_match('/min:([\d.-]+)/', $field['rules'], $match)) {
                    $property->setMin((float) $match[1]);
                }if (preg_match('/max:([\d.-]+)/', $field['rules'], $match)) {
                    $property->setMax((float) $match[1]);
                }
            }
            $values->addProperty($key, $property);
            $qualifier = (new ObjectType)->addProperty('state', (new StringType)->enum(['unknown', 'not_applicable', 'not_present']))->setRequired(['state'])->nullable($input);
            if (($field['type'] ?? null) === 'date') {
                $qualifier->addProperty('precision', (new StringType)->enum(['day', 'year']))->setRequired([]);
            }
            $qualifiers->addProperty($key, $qualifier);
            $checks->addProperty($key, $input ? new BooleanType : (new ObjectType)->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['by', 'at']));
        }
        $scope = (new ObjectType)->addProperty('kind', (new StringType)->enum(['tube', 'coil']))->addProperty('component_id', (new StringType)->format('uuid')->nullable(true))->addProperty('values', $values)->addProperty('qualifiers', $qualifiers)->addProperty($input ? 'verification' : 'checks', $checks)->setRequired(['kind', 'component_id']);
        if (! $input) {
            $scope->addProperty('id', (new StringType)->format('uuid'))->addProperty('name', new StringType)->addProperty('active', new BooleanType)->addProperty('persisted', new BooleanType);
            $scope->setRequired(array_keys($scope->properties));
        }

        return (new ArrayType)->setItems($scope);
    }
}
