<?php

namespace App\Modules\DataExchange\Application;

/** Roll back the exact same write path used by apply, then return its report. */
final class PreviewFinished extends \RuntimeException
{
    public function __construct(public readonly array $report)
    {
        parent::__construct('Dataset preview completed without applying changes.');
    }
}
