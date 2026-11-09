<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION guard_equipment_component_code() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'Component code is immutable; demount or restore the existing component' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER equipment_component_code_guard BEFORE UPDATE OF code ON equipment_components FOR EACH ROW EXECUTE FUNCTION guard_equipment_component_code();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER equipment_component_code_guard ON equipment_components');
        DB::statement('DROP FUNCTION guard_equipment_component_code()');
    }
};
