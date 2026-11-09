<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_components', fn (Blueprint $table) => $table->string('coil_role', 20)->nullable());
        Schema::table('equipment_component_constructions', fn (Blueprint $table) => $table->string('coil_body_kind', 20)->nullable());
        DB::statement("ALTER TABLE equipment_components ADD CHECK (coil_role IS NULL OR (coil_role IN ('inlet','body','outlet') AND parent_id IS NOT NULL))");
        DB::statement("ALTER TABLE equipment_component_constructions ADD CHECK (coil_body_kind IN ('mono','multi'))");
        Schema::create('equipment_component_ports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('component_id')->constrained('equipment_components')->restrictOnDelete();
            $table->string('direction', 10);
            $table->string('mark')->nullable();
            $table->string('service_name')->nullable();
            $table->string('connection_kind')->nullable();
            $table->timestampsTz();
            $table->unique(['component_id', 'direction']);
        });
        DB::statement("ALTER TABLE equipment_component_ports ADD CHECK (direction IN ('inlet','outlet'))");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_coil_element_role() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE coil boolean;
BEGIN
 IF NEW.coil_role IS NULL THEN RETURN NEW; END IF;
 WITH RECURSIVE lineage AS (
  SELECT id,parent_id,is_coil FROM equipment_components WHERE id=NEW.parent_id
  UNION ALL SELECT c.id,c.parent_id,c.is_coil FROM equipment_components c JOIN lineage l ON c.id=l.parent_id
 ) SELECT is_coil INTO coil FROM lineage WHERE parent_id IS NULL;
 IF coil IS DISTINCT FROM TRUE THEN
  RAISE EXCEPTION 'Coil element role requires a coil root' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER coil_element_role BEFORE INSERT OR UPDATE OF coil_role,parent_id ON equipment_components
FOR EACH ROW EXECUTE FUNCTION guard_coil_element_role();
CREATE FUNCTION guard_coil_port() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' AND (NEW.component_id IS DISTINCT FROM OLD.component_id OR NEW.direction IS DISTINCT FROM OLD.direction OR NEW.id IS DISTINCT FROM OLD.id) THEN
  RAISE EXCEPTION 'Port identity is immutable' USING ERRCODE='23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM equipment_components WHERE id=NEW.component_id AND is_coil AND parent_id IS NULL) THEN
  RAISE EXCEPTION 'Port requires an independent coil' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER coil_port BEFORE INSERT OR UPDATE ON equipment_component_ports
FOR EACH ROW EXECUTE FUNCTION guard_coil_port();
CREATE FUNCTION guard_coil_body_kind() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.coil_body_kind IS NOT NULL AND NOT EXISTS (SELECT 1 FROM equipment_components WHERE id=NEW.component_id AND is_coil AND parent_id IS NULL) THEN
  RAISE EXCEPTION 'Coil body kind requires an independent coil' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER coil_body_kind BEFORE INSERT OR UPDATE ON equipment_component_constructions
FOR EACH ROW EXECUTE FUNCTION guard_coil_body_kind();
SQL);
    }

    public function down(): void
    {
        if (DB::table('equipment_component_ports')->exists() || DB::table('equipment_components')->whereNotNull('coil_role')->exists() || DB::table('equipment_component_constructions')->whereNotNull('coil_body_kind')->exists()) {
            throw new RuntimeException('Cannot remove populated coil assembly details.');
        }
        DB::statement('DROP TRIGGER coil_body_kind ON equipment_component_constructions');
        DB::statement('DROP FUNCTION guard_coil_body_kind()');
        DB::statement('DROP TRIGGER coil_port ON equipment_component_ports');
        DB::statement('DROP FUNCTION guard_coil_port()');
        DB::statement('DROP TRIGGER coil_element_role ON equipment_components');
        DB::statement('DROP FUNCTION guard_coil_element_role()');
        Schema::dropIfExists('equipment_component_ports');
        Schema::table('equipment_components', fn (Blueprint $table) => $table->dropColumn('coil_role'));
        Schema::table('equipment_component_constructions', fn (Blueprint $table) => $table->dropColumn('coil_body_kind'));
    }
};
