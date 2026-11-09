<?php

namespace App\Modules\ProcessFlows\Infrastructure;

use App\Modules\ProcessFlows\Domain\FlowEnvironmentSchema;

final class FlowScreeningProjection
{
    public static function definition(): string
    {
        $pairs = ["'ph',p.ph"];
        foreach (FlowEnvironmentSchema::COLUMNS as $key => [$column]) {
            $pairs[] = "'".$key."',s.".$column;
        }
        $values = implode(',', $pairs);

        return <<<SQL
            CREATE VIEW process_flow_screening_cards AS
            SELECT s.flow_id,jsonb_build_object('values',jsonb_strip_nulls(jsonb_build_object($values)),
                'comments',coalesce(c.comments,'{}'::jsonb),'thresholds',coalesce(t.thresholds,'{}'::jsonb),
                'updated_by',s.updated_by,'updated_at',(to_jsonb(s.updated_at AT TIME ZONE 'UTC')#>>'{}')||'+00:00') AS state
            FROM process_flow_environments s
            LEFT JOIN process_flow_properties p ON p.flow_id=s.flow_id
            LEFT JOIN LATERAL (SELECT jsonb_object_agg(item_id,comment) AS comments FROM process_flow_screening_comments WHERE flow_id=s.flow_id) c ON true
            LEFT JOIN LATERAL (
                SELECT jsonb_object_agg(question_id,jsonb_build_object('threshold',CASE WHEN bound_count=2 THEN jsonb_build_array(bound_1,bound_2) ELSE jsonb_build_array(bound_1) END,
                    'near',CASE near_count WHEN 0 THEN '[]'::jsonb WHEN 1 THEN jsonb_build_array(near_1) ELSE jsonb_build_array(near_1,near_2) END)) AS thresholds
                FROM process_flow_screening_thresholds WHERE flow_id=s.flow_id
            ) t ON true
            SQL;
    }
}
