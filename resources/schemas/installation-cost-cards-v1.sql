CREATE VIEW installation_cost_cards AS
SELECT i.id AS installation_id,coalesce(a.values,'{}'::jsonb) AS values,
    coalesce(a.sources,'{}'::jsonb) AS sources,coalesce(a.checks,'{}'::jsonb) AS checks
FROM installations i LEFT JOIN installation_cost_parameters p ON p.installation_id=i.id
LEFT JOIN LATERAL (
    SELECT jsonb_object_agg(s.field_key,to_jsonb(p)->s.field_key) FILTER (WHERE s.value_present) AS values,
        jsonb_object_agg(s.field_key,jsonb_build_object('citation',s.citation,'conditions',s.conditions,'by',s.source_by,
            'at',(to_jsonb(s.source_at AT TIME ZONE 'UTC')#>>'{}')||'+00:00')) FILTER (WHERE s.source_present) AS sources,
        jsonb_object_agg(s.field_key,jsonb_build_object('by',s.verified_by,
            'at',(to_jsonb(s.verified_at AT TIME ZONE 'UTC')#>>'{}')||'+00:00')) FILTER (WHERE s.verified_at IS NOT NULL) AS checks
    FROM installation_cost_annotations s WHERE s.installation_id=i.id
) a ON true;
