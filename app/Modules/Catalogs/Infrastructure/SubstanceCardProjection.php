<?php

namespace App\Modules\Catalogs\Infrastructure;

use App\Modules\Catalogs\Domain\SubstancePropertySchema;

final class SubstanceCardProjection
{
    public static function effectiveSql(): string
    {
        return "(base_values || coalesce((SELECT c.overrides FROM substance_cards c WHERE c.substance_id=substances.id),'{}'::jsonb))";
    }

    public static function definition(): string
    {
        $parts = [];
        foreach (SubstancePropertySchema::fields() as $key => $field) {
            if ($field['column'] !== null) {
                $parts[] = "WHEN '".$key."' THEN to_jsonb(p.\"".$field['column'].'")';
            }
        }
        $value = 'CASE a.field_key '.implode(' ', $parts).' ELSE NULL::jsonb END';

        return "CREATE VIEW substance_cards AS
            SELECT s.id AS substance_id, coalesce(v.overrides,'{}'::jsonb) AS overrides,
                coalesce(v.sources,'{}'::jsonb) AS sources, coalesce(v.checks,'{}'::jsonb) AS checks
            FROM substances s LEFT JOIN substance_properties p ON p.substance_id=s.id
            LEFT JOIN LATERAL (
                SELECT jsonb_object_agg(a.field_key, coalesce(to_jsonb(a.qualifier), $value, 'null'::jsonb)) FILTER (WHERE a.value_present) AS overrides,
                    jsonb_object_agg(a.field_key,jsonb_build_object('citation',a.citation,'conditions',a.conditions,'by',a.source_by,
                        'at',(to_jsonb(a.source_at AT TIME ZONE 'UTC')#>>'{}')||'+00:00')) FILTER (WHERE a.source_present) AS sources,
                    jsonb_object_agg(a.field_key,jsonb_build_object('by',a.verified_by,
                        'at',(to_jsonb(a.verified_at AT TIME ZONE 'UTC')#>>'{}')||'+00:00')) FILTER (WHERE a.verified_at IS NOT NULL) AS checks
                FROM substance_property_annotations a WHERE a.substance_id=s.id
            ) v ON true";
    }
}
