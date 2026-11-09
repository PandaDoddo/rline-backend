<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE equipment_cmls ALTER COLUMN point_count DROP NOT NULL;
ALTER TABLE equipment_cmls ADD CONSTRAINT equipment_cml_point_count_check CHECK (point_count BETWEEN 1 AND 100);
CREATE FUNCTION guard_cml_point_count() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.point_count IS DISTINCT FROM OLD.point_count AND EXISTS (SELECT 1 FROM equipment_thickness_values WHERE cml_id=OLD.id) THEN
  IF OLD.point_count IS NOT NULL OR NEW.point_count IS NULL OR EXISTS (
   SELECT 1 FROM equipment_thickness_points p JOIN equipment_thickness_values m ON m.id=p.measurement_id
   WHERE m.cml_id=OLD.id AND p.point_number>NEW.point_count
  ) THEN
   RAISE EXCEPTION 'Measured CML count may only clarify an unknown plan without dropping coordinates' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER cml_point_count_guard BEFORE UPDATE OF point_count ON equipment_cmls
 FOR EACH ROW EXECUTE FUNCTION guard_cml_point_count();
CREATE FUNCTION guard_thickness_point_coordinate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE planned_count integer;
BEGIN
 SELECT c.point_count INTO planned_count FROM equipment_thickness_values m JOIN equipment_cmls c ON c.id=m.cml_id WHERE m.id=NEW.measurement_id;
 IF NEW.point_number>planned_count THEN
  RAISE EXCEPTION 'Thickness point exceeds the declared CML count' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER thickness_point_coordinate_guard BEFORE INSERT OR UPDATE ON equipment_thickness_points
 FOR EACH ROW EXECUTE FUNCTION guard_thickness_point_coordinate();
SQL);
    }

    public function down(): void
    {
        if (DB::table('equipment_cmls')->whereNull('point_count')->exists()) {
            throw new RuntimeException('Cannot fabricate a planned count for an unknown CML.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER thickness_point_coordinate_guard ON equipment_thickness_points;
DROP FUNCTION guard_thickness_point_coordinate();
DROP TRIGGER cml_point_count_guard ON equipment_cmls;
DROP FUNCTION guard_cml_point_count();
ALTER TABLE equipment_cmls DROP CONSTRAINT equipment_cml_point_count_check;
ALTER TABLE equipment_cmls ALTER COLUMN point_count SET NOT NULL;
SQL);
    }
};
