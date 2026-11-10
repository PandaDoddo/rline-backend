<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $invalid = DB::table('equipment_component_rbi_settings as s')->join('equipment_components as c', 'c.id', '=', 's.component_id')
            ->whereNotNull('c.parent_id')->where(fn ($query) => $query->whereNotNull('s.injection_point')->orWhereNotNull('s.dead_leg'))->exists();
        if ($invalid) {
            throw new RuntimeException('Point flags must be explicitly resolved at the root component before enabling ownership guards.');
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_component_rbi_point_flag_owner() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.injection_point IS NOT NULL OR NEW.dead_leg IS NOT NULL) AND EXISTS(SELECT 1 FROM equipment_components c WHERE c.id=NEW.component_id AND c.equipment_id=NEW.equipment_id AND c.parent_id IS NOT NULL) THEN
  RAISE EXCEPTION 'RBI injection and dead-leg flags require a root component owner' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER component_rbi_point_owner BEFORE INSERT OR UPDATE ON equipment_component_rbi_settings FOR EACH ROW EXECUTE FUNCTION guard_component_rbi_point_flag_owner();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER component_rbi_point_owner ON equipment_component_rbi_settings');
        DB::statement('DROP FUNCTION guard_component_rbi_point_flag_owner()');
    }
};
