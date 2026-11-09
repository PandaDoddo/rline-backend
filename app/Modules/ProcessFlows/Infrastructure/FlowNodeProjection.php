<?php

namespace App\Modules\ProcessFlows\Infrastructure;

use Illuminate\Database\Eloquent\Builder;

class FlowNodeProjection
{
    public static function path(Builder $query): Builder
    {
        return $query->selectRaw(<<<'SQL'
            (WITH RECURSIVE ancestors AS (
                SELECT a.id, a.parent_id, a.name, 0 AS depth FROM process_flow_nodes a WHERE a.id = process_flow_nodes.id
                UNION ALL SELECT p.id, p.parent_id, p.name, a.depth + 1 FROM process_flow_nodes p JOIN ancestors a ON p.id = a.parent_id WHERE a.depth < 7
            ) SELECT string_agg(name, ' / ' ORDER BY depth DESC) FROM ancestors) AS path
            SQL);
    }
}
