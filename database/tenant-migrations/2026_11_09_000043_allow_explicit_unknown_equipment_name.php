<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE equipment ALTER COLUMN name DROP NOT NULL;
CREATE FUNCTION guard_equipment_name_state() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE entity uuid; actual_name text; actual_state text;
BEGIN
 IF TG_TABLE_NAME='equipment' THEN entity=NEW.id;
 ELSE
  IF TG_OP='UPDATE' AND (OLD.equipment_id,OLD.field_key) IS DISTINCT FROM (NEW.equipment_id,NEW.field_key) THEN
   RAISE EXCEPTION 'Passport annotation identity is immutable' USING ERRCODE='23514';
  END IF;
  IF COALESCE(NEW.field_key,OLD.field_key)<>'name' THEN RETURN NULL; END IF;
  entity=COALESCE(NEW.equipment_id,OLD.equipment_id);
 END IF;
 SELECT e.name,a.state INTO actual_name,actual_state FROM equipment e
 LEFT JOIN equipment_passport_annotations a ON a.equipment_id=e.id AND a.field_key='name' WHERE e.id=entity;
 IF FOUND AND ((actual_name IS NULL AND actual_state IS DISTINCT FROM 'unknown') OR (actual_name IS NOT NULL AND actual_state IS NOT NULL)) THEN
  RAISE EXCEPTION 'Unknown equipment name must have an explicit unknown state and no fabricated value' USING ERRCODE='23514';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER equipment_name_state AFTER INSERT OR UPDATE ON equipment
 DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_equipment_name_state();
CREATE CONSTRAINT TRIGGER equipment_name_annotation_state AFTER INSERT OR UPDATE OR DELETE ON equipment_passport_annotations
 DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_equipment_name_state();
SQL);
    }

    public function down(): void
    {
        if (DB::table('equipment')->whereNull('name')->exists()) {
            throw new RuntimeException('Cannot replace unknown equipment names with fabricated values.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER equipment_name_annotation_state ON equipment_passport_annotations;
DROP TRIGGER equipment_name_state ON equipment;
DROP FUNCTION guard_equipment_name_state();
ALTER TABLE equipment ALTER COLUMN name SET NOT NULL;
SQL);
    }
};
