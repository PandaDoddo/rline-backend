<?php

namespace App\Documentation;

use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class ComponentRbiApiSchema
{
    public static function make(bool $input = false): ArrayType
    {
        $row = (new ObjectType)->addProperty('component_id', (new StringType)->format('uuid'))
            ->addProperty('weld_efficiency', (new NumberType)->setMin(0)->setMax(1)->nullable(true)->setDescription('Явный коэффициент сварного соединения: 0 < E ≤ 1; null — не задан.'))
            ->addProperty('injection_point', (new BooleanType)->nullable(true))
            ->addProperty('dead_leg', (new BooleanType)->nullable(true))
            ->addProperty('confidence', (new StringType)->enum(['low', 'med', 'high'])->nullable(true))
            ->addProperty('notes', (new StringType)->nullable(true));
        if (! $input) {
            $row->addProperty('updated_by', new IntegerType)->addProperty('updated_at', (new StringType)->format('date-time'));
        }
        $row->setRequired($input ? ['component_id'] : array_keys($row->properties));

        return (new ArrayType)->setItems($row);
    }
}
