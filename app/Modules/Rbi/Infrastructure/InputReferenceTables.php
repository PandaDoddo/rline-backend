<?php

namespace App\Modules\Rbi\Infrastructure;

/** Frozen defaults only. Tenant overrides must be resolved and stamped by the application layer. */
final class InputReferenceTables
{
    public function snapshot(): array
    {
        static $snapshot;

        return $snapshot ??= json_decode(file_get_contents(dirname(__DIR__, 4).'/resources/rbi/input-tables-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
