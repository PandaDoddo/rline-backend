<?php

namespace App\Modules\Rbi\Infrastructure;

/** Immutable base tables; tenant overrides must be resolved into a separate versioned snapshot. */
final class ReferenceTables
{
    public function snapshot(): array
    {
        static $snapshot;

        return $snapshot ??= json_decode(file_get_contents(resource_path('rbi/reference-tables-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
