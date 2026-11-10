<?php

namespace App\Modules\DataExchange\Application;

use Illuminate\Support\Collection;

/** One batched query, including source issue counts, for a page or a single flow. */
final class FlowOrigins
{
    public function __construct(private readonly ImportedRecordOrigins $origins) {}

    public function attach(Collection $flows): void
    {
        $this->origins->attach($flows, 'flow');
    }
}
