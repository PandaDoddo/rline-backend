<?php

namespace App\Modules\Catalogs\Infrastructure;

use Illuminate\Database\Eloquent\Builder;

class CatalogListProjection
{
    public static function steelCosts(Builder $query): Builder
    {
        $query->select(['id', 'label', 'version']);
        foreach (['base_values', 'overrides', 'checks', 'sources'] as $column) {
            $keys = in_array($column, ['checks', 'sources'], true) ? "'accepted_cost','cost_reason'" : "'name','kind','subtype','refine','composition','accepted_cost','cost_reason'";
            $query->selectRaw("(SELECT coalesce(jsonb_object_agg(key, value), '{}'::jsonb) FROM jsonb_each(".$column.') WHERE key IN ('.$keys.')) AS '.$column);
        }

        return $query;
    }

    public static function damageMechanisms(Builder $query): Builder
    {
        return $query->select(['id', 'legacy_code', 'label', 'status', 'version'])->selectRaw("(base_values || overrides)->>'english_name' AS summary_english_name, (base_values || overrides)->>'paragraph' AS summary_paragraph, (base_values || overrides)->>'designation' AS summary_designation, (base_values || overrides)->>'kind' AS summary_kind, (base_values || overrides)->>'df_mapping' AS summary_df_mapping, (base_values || overrides)->'material_groups' AS summary_material_groups");
    }

    public static function substances(Builder $query): Builder
    {
        $effective = SubstanceCardProjection::effectiveSql();

        return $query->without('cardProjection')->select(['id', 'label', 'origin', 'status', 'version', 'base_flags'])
            ->selectRaw('(SELECT c.checks FROM substance_cards c WHERE c.substance_id=substances.id) AS checks')
            ->selectRaw($effective."->>'formula' AS summary_formula, ".$effective."->>'grpRF' AS summary_group");
    }

    public static function steelGrades(Builder $query): Builder
    {
        return $query->select(['id', 'label', 'origin', 'status', 'version'])
            ->selectRaw("(base_values || overrides)->>'analog' AS summary_analog, (base_values || overrides)->>'subtype' AS summary_subtype, (base_values || overrides)->>'std' AS summary_standard");
    }
}
