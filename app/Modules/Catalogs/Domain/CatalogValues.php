<?php

namespace App\Modules\Catalogs\Domain;

use App\Modules\Shared\Domain\ValueEquality;

/** @deprecated Kept for already released migration references. */
class CatalogValues
{
    public static function same(mixed $before, mixed $after): bool
    {
        return ValueEquality::same($before, $after);
    }
}
