<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE equipment_thickness_stages ALTER COLUMN inspection_id DROP NOT NULL');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_thickness_stage_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.equipment_id IS DISTINCT FROM OLD.equipment_id OR NEW.inspection_id IS DISTINCT FROM OLD.inspection_id THEN
  RAISE EXCEPTION 'Thickness stage ownership and inspection link are immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER thickness_stage_identity BEFORE UPDATE ON equipment_thickness_stages
FOR EACH ROW EXECUTE FUNCTION guard_thickness_stage_identity();
SQL);
    }

    public function down(): void
    {
        if (DB::table('equipment_thickness_stages')->whereNull('inspection_id')->exists()) {
            throw new RuntimeException('Cannot remove independent thickness stages without losing measurement history.');
        }
        DB::statement('DROP TRIGGER thickness_stage_identity ON equipment_thickness_stages');
        DB::statement('DROP FUNCTION guard_thickness_stage_identity()');
        DB::statement('ALTER TABLE equipment_thickness_stages ALTER COLUMN inspection_id SET NOT NULL');
    }
};
