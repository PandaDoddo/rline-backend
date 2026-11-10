CREATE VIEW installation_maintenance_cards AS
SELECT r.id AS record_id,coalesce(v.values,'{}'::jsonb) AS "values",
    CASE WHEN r.verified_at IS NULL THEN NULL ELSE jsonb_build_object('by',r.verified_by,
        'at',(to_jsonb(r.verified_at AT TIME ZONE 'UTC')#>>'{}')||'+00:00') END AS "check"
FROM installation_maintenance_records r
LEFT JOIN installation_mri_parameters m ON m.record_id=r.id
LEFT JOIN installation_repair_parameters p ON p.record_id=r.id
LEFT JOIN installation_stop_parameters s ON s.record_id=r.id
LEFT JOIN LATERAL (
    SELECT jsonb_object_agg(a.field_key,coalesce(CASE a.field_key
        WHEN 'reason' THEN to_jsonb(r.reason)
        WHEN 'effective_date' THEN to_jsonb(m.effective_date)
        WHEN 'interval_months' THEN to_jsonb(m.interval_months)
        WHEN 'starts_on' THEN to_jsonb(p.starts_on)
        WHEN 'ends_on' THEN to_jsonb(p.ends_on)
        WHEN 'repair_kind' THEN to_jsonb(p.repair_kind)
        WHEN 'starts_at' THEN to_jsonb(to_char(s.starts_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'))
        WHEN 'ends_at' THEN to_jsonb(to_char(s.ends_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'))
        END,'null'::jsonb)) AS "values"
    FROM installation_maintenance_presence a WHERE a.record_id=r.id
) v ON true;
