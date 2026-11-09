<?php

namespace App\Modules\Diagnostics\Infrastructure;

/** Versioned SQL projection. Changes require a tenant migration to rebuild the function. */
class DiagnosticPlanProjection
{
    public static function definition(array $profiles, bool $normalized = false): string
    {
        $rules = [];
        foreach ($profiles as $code => $profile) {
            $columns = array_unique([...$profile['cols'], ...($profile['g'] === 'ves' ? ['ТО-2'] : [])]);
            foreach ($columns as $target) {
                $sources = match ($target) {
                    'ТО-1' => ['ТО-2', 'ЭПБ'],
                    'ТО-2' => ['ЭПБ'],
                    'НО' => $profile['g'] === 'furnace' ? [] : ['ТО-1', 'ТО-2', 'ЭПБ'],
                    'ГЕОД-И' => match ($code) {
                        'tank_vert' => ['ТО-1', 'ТО-2', 'ЭПБ'],
                        'ves_sphere' => ['ЭПБ'],
                        'tank_iso' => ['ТО-1', 'ЭПБ'],
                        default => [],
                    },
                    default => [],
                };
                foreach (array_intersect($sources, $columns) as $source) {
                    // Values are checked-in profile identifiers, never request input.
                    $quote = fn ($s) => "'".str_replace("'", "''", $s)."'";
                    $rules[] = '('.implode(',', array_map($quote, [$code, $target, $source, $profile['rp']])).','.($target === 'ТО-2' && $profile['g'] === 'ves' ? 'TRUE' : 'FALSE').')';
                }
            }
        }
        $values = implode(",\n", $rules);
        $sql = str_replace('__RULES__', $values, <<<'SQL'
CREATE FUNCTION equipment_diagnostic_plan(p_equipment uuid)
RETURNS TABLE(kind text, performed_on date, planned_on date, absorbed_by text, absorbed_on date, performed_by text)
LANGUAGE SQL STABLE PARALLEL SAFE AS $plan$
WITH rules(profile_code,target_kind,source_kind,default_registration,rtn_only) AS (VALUES __RULES__),
own AS (
    SELECT i.*,e.profile_code,e.passport FROM equipment_inspections i JOIN equipment e ON e.id=i.equipment_id
    WHERE i.equipment_id=p_equipment
), expanded AS (
    SELECT i.id,i.kind,i.performed_on,i.kind source_kind FROM own i
    UNION ALL
    SELECT i.id,r.target_kind,i.performed_on,i.kind source_kind FROM own i
    JOIN rules r ON r.profile_code=i.profile_code AND r.source_kind=i.kind WHERE i.performed_on IS NOT NULL
    AND (NOT r.rtn_only OR COALESCE(i.passport->>'registration_authority',r.default_registration)='РТН')
), facts AS (
    SELECT DISTINCT ON (kind) kind,performed_on,source_kind
    FROM expanded ORDER BY kind,performed_on DESC NULLS LAST,(kind=source_kind) DESC,id
), issued AS (
    SELECT id,kind,performed_on issued_on,COALESCE(next_on,planned_on) planned_on,kind source_kind FROM own WHERE performed_on IS NOT NULL
    UNION ALL
    SELECT i.id,j.key,i.performed_on,j.value::date,i.kind
    FROM own i CROSS JOIN LATERAL jsonb_each_text(CASE WHEN jsonb_typeof(i.follow_up_dates)='object' THEN i.follow_up_dates ELSE '{}'::jsonb END) j
    JOIN rules r ON r.profile_code=i.profile_code AND r.source_kind=i.kind AND r.target_kind=j.key
    WHERE i.performed_on IS NOT NULL AND (NOT r.rtn_only OR COALESCE(i.passport->>'registration_authority',r.default_registration)='РТН')
    UNION ALL
    SELECT i.id,r.target_kind,i.performed_on,
        (i.performed_on + CASE r.target_kind
            WHEN 'ТО-1' THEN make_interval(years=>NULLIF(i.passport->>'active_otn_years','')::int)
            WHEN 'ТО-2' THEN make_interval(years=>NULLIF(i.passport->>'active_rtn_years','')::int)
            WHEN 'НО' THEN INTERVAL '12 months'
            WHEN 'ГЕОД-И' THEN INTERVAL '60 months'
        END)::date,i.kind
    FROM own i JOIN rules r ON r.profile_code=i.profile_code AND r.source_kind=i.kind
    WHERE i.performed_on IS NOT NULL AND NOT (i.follow_up_dates ? r.target_kind)
    AND (NOT r.rtn_only OR COALESCE(i.passport->>'registration_authority',r.default_registration)='РТН')
), ranked AS (
    SELECT i.*,ROW_NUMBER() OVER (PARTITION BY i.kind ORDER BY i.issued_on DESC,
        CASE WHEN i.kind NOT IN ('ТО-1','ТО-2','НО','ГЕОД-И') AND i.kind=i.source_kind THEN 0 ELSE 1 END,i.planned_on NULLS LAST,i.id) position
    FROM issued i LEFT JOIN facts f ON f.kind=i.kind WHERE f.performed_on IS NULL OR i.issued_on>=f.performed_on
), pending AS (
    SELECT kind,planned_on FROM own WHERE performed_on IS NULL AND planned_on IS NOT NULL
    UNION ALL
    SELECT kind,planned_on FROM ranked WHERE position=1 AND planned_on IS NOT NULL
), plans AS (
    SELECT kind,MIN(planned_on) planned_on FROM pending GROUP BY kind
), basis AS (
    SELECT COALESCE(f.kind,p.kind) kind,f.performed_on,p.planned_on,f.source_kind
    FROM facts f FULL JOIN plans p ON f.kind=p.kind
)
SELECT b.kind::text,b.performed_on,b.planned_on,a.kind::text,a.planned_on,
    CASE WHEN b.performed_on IS NOT NULL AND b.source_kind<>b.kind THEN b.source_kind::text END
FROM basis b LEFT JOIN LATERAL (
    SELECT other.kind,other.planned_on FROM basis other
    WHERE b.planned_on IS NOT NULL AND other.planned_on IS NOT NULL
    AND ((b.kind='ТО-1' AND other.kind IN ('ТО-2','ЭПБ')) OR (b.kind='ТО-2' AND other.kind='ЭПБ'))
    AND (EXTRACT(YEAR FROM b.planned_on)=EXTRACT(YEAR FROM other.planned_on) OR ABS(b.planned_on-other.planned_on)<=30)
    ORDER BY CASE other.kind WHEN 'ЭПБ' THEN 0 ELSE 1 END,other.planned_on LIMIT 1
) a ON TRUE
$plan$
SQL);
        if ($normalized) {
            $sql = str_replace('SELECT i.*,e.profile_code,e.passport FROM equipment_inspections i JOIN equipment e ON e.id=i.equipment_id',
                'SELECT i.*,e.profile_code,c.registration_authority,d.active_otn_years,d.active_rtn_years FROM equipment_inspections i JOIN equipment e ON e.id=i.equipment_id LEFT JOIN equipment_classifications c ON c.equipment_id=e.id LEFT JOIN equipment_diagnostic_settings d ON d.equipment_id=e.id', $sql);
            $sql = str_replace("i.passport->>'registration_authority'", 'i.registration_authority', $sql);
            $sql = str_replace(["NULLIF(i.passport->>'active_otn_years','')::int", "NULLIF(i.passport->>'active_rtn_years','')::int"], ['i.active_otn_years', 'i.active_rtn_years'], $sql);
        }

        return $sql;
    }
}
