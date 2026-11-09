<?php

namespace App\Modules\ProcessFlows\Infrastructure;

final class FlowCardProjection
{
    public static function definition(): string
    {
        return <<<'SQL'
            CREATE VIEW process_flow_cards AS
            SELECT f.id AS flow_id,coalesce(a.attributes,'{}'::jsonb) AS attributes,
                coalesce(a.sources,'{}'::jsonb) AS sources,coalesce(a.checks,'{}'::jsonb) AS checks,
                CASE WHEN c.id IS NULL THEN NULL ELSE jsonb_build_object('id',c.id,'signature',c.signature,'by',c.created_by,
                    'at',(to_jsonb(c.created_at AT TIME ZONE 'UTC')#>>'{}')||'+00:00','properties',c.result->'properties') END AS accepted_calculation
            FROM process_flow_nodes f
            LEFT JOIN process_flow_properties p ON p.flow_id=f.id
            LEFT JOIN LATERAL (
                SELECT jsonb_object_agg(s.field_key,to_jsonb(p)->s.field_key) FILTER (WHERE s.value_present) AS attributes,
                    jsonb_object_agg(s.field_key,jsonb_build_object('citation',s.citation,'conditions',s.conditions,'by',s.source_by,
                        'at',(to_jsonb(s.source_at AT TIME ZONE 'UTC')#>>'{}')||'+00:00')) FILTER (WHERE s.source_present) AS sources,
                    jsonb_object_agg(s.field_key,jsonb_build_object('by',s.verified_by,
                        'at',(to_jsonb(s.verified_at AT TIME ZONE 'UTC')#>>'{}')||'+00:00')) FILTER (WHERE s.verified_at IS NOT NULL) AS checks
                FROM process_flow_property_annotations s WHERE s.flow_id=f.id
            ) a ON true
            LEFT JOIN process_flow_calculations c ON c.id=f.accepted_calculation_id AND c.flow_id=f.id
            SQL;
    }
}
