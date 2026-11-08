<?php

namespace App\Documentation;

use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;

class CorrosionSchema
{
    private static function object(array $fields): ObjectType
    {
        $type = new ObjectType;
        foreach ($fields as $name => $field) {
            $type->addProperty($name, $field);
        }

        return $type->setRequired(array_keys($fields));
    }

    public static function make(): ObjectType
    {
        $number = fn () => (new NumberType)->nullable(true);
        $life = fn () => (new AnyOf)->setItems([$number(), (new StringType)->enum(['infinite'])]);
        $bases = fn (Type $value) => self::object(['nominal' => $value->clone(), 'first' => $value->clone(), 'short' => $value->clone()]);
        $values = self::object(['min' => new NumberType, 'ave' => new NumberType, 'max' => new NumberType]);
        $series = self::object(['stage_id' => (new StringType)->format('uuid'), 'date' => (new StringType)->format('date'), 'values' => $values,
            'complete' => new BooleanType, 'rates' => self::object(['min' => $bases($number()), 'ave' => $bases($number())]),
            'life' => self::object(['min' => $bases($life()), 'ave' => $bases($life())]), 'status' => (new StringType)->enum(['unknown', 'ok', 'warning', 'rejected']),
            'wear_percent' => self::object(['min' => $number(), 'ave' => $number()]), 'date_anomaly' => new BooleanType]);
        $element = self::object(['id' => (new StringType)->format('uuid'), 'code' => new StringType, 'name' => new StringType, 'scope_id' => new StringType,
            'nominal_mm' => $number(), 'rejection_mm' => $number(), 'rejection_source' => (new StringType)->enum(['epb', 'passport'])->nullable(true),
            'series' => (new ArrayType)->setItems($series), 'latest' => $series->clone()->nullable(true), 'next_uzt_on' => (new StringType)->format('date')->nullable(true), 'life_warning' => new BooleanType]);
        $scope = self::object(['id' => new StringType, 'name' => new StringType, 'elements' => new IntegerType, 'rejected' => new IntegerType, 'warning' => new IntegerType,
            'life_warning' => new IntegerType, 'max_rate_mm_year' => $number(), 'min_life_years' => $life(), 'average_wear_percent' => $number()]);
        $period = self::object(['ntd' => (new StringType)->nullable(true), 'registration' => (new StringType)->nullable(true), 'applicable' => new BooleanType, 'message' => (new StringType)->nullable(true),
            'long_speed' => $number(), 'short_speed' => $number(), 'design_speed' => $number(), 'accepted_speed' => $number(),
            'otn_years' => (new IntegerType)->nullable(true), 'rtn_years' => (new IntegerType)->nullable(true), 'hydro_years' => (new IntegerType)->nullable(true), 'frequency_increased' => new BooleanType]);
        $history = $period->clone()->addProperty('date', (new StringType)->format('date'))->addProperty('changed', new BooleanType);
        $history->setRequired([...$period->required, 'date', 'changed']);

        $cml = $element->clone()->addProperty('element_id', (new StringType)->format('uuid'));
        $cml->setRequired([...$element->required, 'element_id']);
        $anomaly = self::object(['type' => new StringType, 'level' => (new StringType)->enum(['element', 'point', 'stage']), 'stage_id' => (new StringType)->format('uuid'),
            'component_id' => (new StringType)->format('uuid')->nullable(true), 'cml_id' => (new StringType)->format('uuid')->nullable(true), 'point' => (new IntegerType)->nullable(true), 'message' => new StringType]);

        return self::object(['method' => new StringType, 'elements' => (new ArrayType)->setItems($element), 'cmls' => (new ArrayType)->setItems($cml), 'scopes' => (new ArrayType)->setItems($scope),
            'periodicity' => self::object(['current' => $period, 'history' => (new ArrayType)->setItems($history)]), 'anomalies' => (new ArrayType)->setItems($anomaly)]);
    }
}
