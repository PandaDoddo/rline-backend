<?php

namespace App\Modules\Rbi\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Preserve the original RBI calendar-year convention; it differs from epoch-based corrosion time. */
final class RbiDate
{
    public const VERSION = 'rbi-calendar-year-365.25-v1';

    public static function decimal(string $iso): float
    {
        $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $iso) ? DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')) : false;
        if (! $date || $date->format('Y-m-d') !== $iso || (int) $date->format('Y') < 1) {
            throw new InvalidArgumentException('Для RBI требуется корректная календарная дата YYYY-MM-DD.');
        }
        $year = (int) $date->format('Y');
        $start = $date->setDate($year, 1, 1);

        return $year + ($date->getTimestamp() - $start->getTimestamp()) / 86400 / 365.25;
    }
}
