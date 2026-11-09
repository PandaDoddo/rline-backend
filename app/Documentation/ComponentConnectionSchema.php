<?php

namespace App\Documentation;

use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class ComponentConnectionSchema
{
    public static function make(): ArrayType
    {
        $row = (new ObjectType)->addProperty('id', (new StringType)->format('uuid'))
            ->addProperty('status', (new StringType)->enum(['active', 'removed']))->addProperty('position', new IntegerType)
            ->addProperty('side', (new StringType)->enum(['coil'])->nullable(true))
            ->addProperty('collar_present', (new BooleanType)->nullable(true))->addProperty('complete', new BooleanType)
            ->addProperty('targets', (new ArrayType)->setItems((new StringType)->format('uuid')))
            ->addProperty('unresolved_targets', (new ArrayType)->setItems(new StringType));
        foreach (['joining_method', 'thread_kind', 'thread_size', 'notes', 'source_reference'] as $field) {
            $row->addProperty($field, (new StringType)->nullable(true));
        }
        $row->setRequired(array_keys($row->properties));

        return (new ArrayType)->setItems($row);
    }
}
