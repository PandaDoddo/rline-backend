<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_equipment_cml_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.id,NEW.equipment_id,NEW.component_id,NEW.code) IS DISTINCT FROM (OLD.id,OLD.equipment_id,OLD.component_id,OLD.code) THEN
  RAISE EXCEPTION 'CML identity and physical owner are immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER equipment_cml_identity_guard BEFORE UPDATE ON equipment_cmls
 FOR EACH ROW EXECUTE FUNCTION guard_equipment_cml_identity();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER equipment_cml_identity_guard ON equipment_cmls');
        DB::statement('DROP FUNCTION guard_equipment_cml_identity()');
    }
};
