<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_component_rbi_settings', function (Blueprint $table) {
            $table->uuid('component_id')->primary();
            $table->uuid('equipment_id')->index();
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->double('weld_efficiency')->nullable();
            $table->boolean('injection_point')->nullable();
            $table->boolean('dead_leg')->nullable();
            $table->string('confidence', 8)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz(6);
        });
        DB::statement('ALTER TABLE equipment_component_rbi_settings ADD CHECK (weld_efficiency > 0 AND weld_efficiency <= 1)');
        DB::statement("ALTER TABLE equipment_component_rbi_settings ADD CHECK (confidence IN ('low','med','high'))");
        DB::statement('ALTER TABLE equipment_component_rbi_settings ADD CHECK (char_length(btrim(notes)) BETWEEN 1 AND 4000)');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION serialize_component_rbi_write() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE equipment SET version=version WHERE id=CASE WHEN TG_OP='DELETE' THEN OLD.equipment_id ELSE NEW.equipment_id END;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 IF TG_OP='UPDATE' AND ROW(NEW.equipment_id,NEW.component_id) IS DISTINCT FROM ROW(OLD.equipment_id,OLD.component_id) THEN
  RAISE EXCEPTION 'Component RBI settings identity is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER component_rbi_write_owner BEFORE INSERT OR UPDATE OR DELETE ON equipment_component_rbi_settings FOR EACH ROW EXECUTE FUNCTION serialize_component_rbi_write();
SQL);
    }

    public function down(): void
    {
        if (DB::table('equipment_component_rbi_settings')->exists()) {
            throw new RuntimeException('Cannot discard component RBI settings.');
        }
        Schema::drop('equipment_component_rbi_settings');
        DB::statement('DROP FUNCTION serialize_component_rbi_write()');
    }
};
