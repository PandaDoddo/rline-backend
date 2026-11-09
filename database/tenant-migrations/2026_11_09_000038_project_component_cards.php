<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE VIEW equipment_component_cards AS
SELECT c.id component_id,
 CASE WHEN k.component_id IS NOT NULL THEN to_jsonb(k)-'{component_id,created_at,updated_at}'::text[] END construction,
 CASE WHEN h.component_id IS NOT NULL THEN to_jsonb(h)-'{component_id,created_at,updated_at}'::text[] END head_geometry,
 CASE WHEN f.component_id IS NOT NULL THEN to_jsonb(f)-'{component_id,created_at,updated_at}'::text[] END fitting_geometry,
 CASE WHEN e.component_id IS NOT NULL THEN to_jsonb(e)-'{component_id,created_at,updated_at}'::text[] END end_connection,
 CASE WHEN b.component_id IS NOT NULL THEN to_jsonb(b)-'{component_id,created_at,updated_at}'::text[] END boss_geometry,
 CASE WHEN v.component_id IS NOT NULL THEN to_jsonb(v)-'{component_id,created_at,updated_at}'::text[] END valve_details,
 CASE WHEN t.component_id IS NOT NULL THEN to_jsonb(t)-'{component_id,created_at,updated_at}'::text[] END tube_geometry,
 CASE WHEN g.component_id IS NOT NULL THEN to_jsonb(g)-'{component_id,created_at,updated_at}'::text[] END coil_geometry,
 CASE WHEN l.component_id IS NOT NULL THEN to_jsonb(l)-'{component_id,created_at,updated_at}'::text[] END lens_geometry,
 CASE WHEN j.component_id IS NOT NULL THEN to_jsonb(j)-'{component_id,created_at,updated_at}'::text[] END expansion_joint,
 CASE WHEN s.component_id IS NOT NULL THEN to_jsonb(s)-'{component_id,created_at,updated_at}'::text[] END shell_geometry,
 COALESCE(p.items,'[]'::jsonb) ports
FROM equipment_components c
LEFT JOIN equipment_component_constructions k ON k.component_id=c.id
LEFT JOIN equipment_component_head_geometries h ON h.component_id=c.id
LEFT JOIN equipment_component_fitting_geometries f ON f.component_id=c.id
LEFT JOIN equipment_component_end_connections e ON e.component_id=c.id
LEFT JOIN equipment_component_boss_geometries b ON b.component_id=c.id
LEFT JOIN equipment_component_valve_details v ON v.component_id=c.id
LEFT JOIN equipment_component_tube_geometries t ON t.component_id=c.id
LEFT JOIN equipment_component_coil_geometries g ON g.component_id=c.id
LEFT JOIN equipment_component_lens_geometries l ON l.component_id=c.id
LEFT JOIN equipment_component_expansion_joints j ON j.component_id=c.id
LEFT JOIN equipment_component_shell_geometries s ON s.component_id=c.id
LEFT JOIN LATERAL (
 SELECT jsonb_agg(jsonb_build_object('id',id,'direction',direction,'mark',mark,'service_name',service_name,'connection_kind',connection_kind) ORDER BY direction) items
 FROM equipment_component_ports WHERE component_id=c.id
) p ON TRUE
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW equipment_component_cards');
    }
};
