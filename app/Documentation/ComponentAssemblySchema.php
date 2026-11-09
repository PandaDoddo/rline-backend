<?php

namespace App\Documentation;

use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class ComponentAssemblySchema
{
    public static function make(): ArrayType
    {
        $row = (new ObjectType)->addProperty('id', (new StringType)->format('uuid'))->addProperty('code', new StringType)
            ->addProperty('kind', (new StringType)->enum(['lens_expansion_joint']))
            ->addProperty('status', (new StringType)->enum(['active', 'removed']))->addProperty('position', new IntegerType)
            ->addProperty('after_component_id', (new StringType)->format('uuid')->nullable(true))
            ->addProperty('nominal_pressure_mpa', (new NumberType)->nullable(true))->addProperty('joint_kind', (new StringType)->enum(['осевой', 'угловой'])->nullable(true))
            ->addProperty('jacket_present', (new BooleanType)->nullable(true))->addProperty('manufacturing_standard', (new StringType)->nullable(true))
            ->addProperty('notes', (new StringType)->nullable(true))->addProperty('source_reference', (new StringType)->nullable(true))
            ->addProperty('members', (new ArrayType)->setItems((new StringType)->format('uuid')));
        $row->setRequired(array_keys($row->properties));

        return (new ArrayType)->setItems($row);
    }
}
