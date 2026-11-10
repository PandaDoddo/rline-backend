<?php

namespace App\Documentation;

use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class ImportOriginSchema
{
    public static function make(): ObjectType
    {
        $schema = (new ObjectType)->addProperty('batch_id', (new StringType)->format('uuid'))->addProperty('kind', (new StringType)->enum(['stored_snapshot', 'demo_expanded']))
            ->addProperty('label', new StringType)->addProperty('source_id', new StringType)->addProperty('source_snapshot_at', (new StringType)->format('date-time'))
            ->addProperty('applied_at', (new StringType)->format('date-time'))->addProperty('imported_version', new IntegerType)->addProperty('changed_since_import', new BooleanType)->addProperty('source_issue_count', new IntegerType);

        return $schema->setRequired(array_keys($schema->properties));
    }
}
