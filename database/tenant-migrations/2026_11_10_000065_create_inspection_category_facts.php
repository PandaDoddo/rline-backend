<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_inspection_category_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->text('group_code');
            $table->uuid('inspection_id')->nullable()->index();
            $table->date('reference_date')->nullable();
            $table->foreign(['inspection_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_inspections')->restrictOnDelete();
            $table->unique(['id', 'equipment_id']);
            $table->unique(['equipment_id', 'group_code', 'inspection_id'], 'icat_diagnostic_group_unique');
            $table->unique(['equipment_id', 'group_code', 'reference_date'], 'icat_manual_group_unique');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz(6);
        });
        DB::statement("ALTER TABLE equipment_inspection_category_stages ADD CHECK (group_code IN ('thin','ext','cui','cl','scc','hic','aus'))");
        DB::statement('ALTER TABLE equipment_inspection_category_stages ADD CHECK ((inspection_id IS NULL) = (reference_date IS NOT NULL))');
        DB::statement("ALTER TABLE equipment_inspection_category_stages ADD CHECK (reference_date BETWEEN DATE '0001-01-01' AND DATE '9999-12-31')");
        Schema::create('equipment_inspection_category_observations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('equipment_id');
            $table->uuid('stage_id');
            $table->uuid('component_id');
            $table->text('category')->nullable();
            $table->boolean('cracks_present')->nullable();
            $table->foreign(['stage_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_inspection_category_stages')->restrictOnDelete();
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->unique(['stage_id', 'component_id'], 'icat_stage_component_unique');
            $table->index(['equipment_id', 'component_id']);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz(6);
        });
        DB::statement("ALTER TABLE equipment_inspection_category_observations ADD CHECK (category IN ('A','B','C','D','E','none'))");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION serialize_inspection_category_write() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE equipment SET version=version WHERE id=COALESCE(NEW.equipment_id,OLD.equipment_id);
 RETURN COALESCE(NEW,OLD);
END; $$;
CREATE FUNCTION protect_inspection_category_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF ROW(NEW.id,NEW.equipment_id) IS DISTINCT FROM ROW(OLD.id,OLD.equipment_id)
    OR (TG_TABLE_NAME='equipment_inspection_category_stages' AND
      ROW(to_jsonb(NEW)->'group_code',to_jsonb(NEW)->'inspection_id',to_jsonb(NEW)->'reference_date') IS DISTINCT FROM ROW(to_jsonb(OLD)->'group_code',to_jsonb(OLD)->'inspection_id',to_jsonb(OLD)->'reference_date'))
    OR (TG_TABLE_NAME='equipment_inspection_category_observations' AND
      ROW(to_jsonb(NEW)->'stage_id',to_jsonb(NEW)->'component_id') IS DISTINCT FROM ROW(to_jsonb(OLD)->'stage_id',to_jsonb(OLD)->'component_id')) THEN
  RAISE EXCEPTION 'Inspection category identity is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION guard_inspection_category_observation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE inspection_group text;
BEGIN
 SELECT group_code INTO inspection_group FROM equipment_inspection_category_stages WHERE id=NEW.stage_id FOR UPDATE;
 IF inspection_group IN ('thin','ext','cui') AND NEW.cracks_present IS NOT NULL THEN
  RAISE EXCEPTION 'Crack facts require a cracking inspection group' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION guard_inspection_category_calendar() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner_id uuid:=COALESCE(NEW.equipment_id,OLD.equipment_id);
BEGIN
 IF EXISTS(SELECT 1 FROM equipment_inspection_category_stages s JOIN equipment_inspections i ON i.id=s.inspection_id
   WHERE s.equipment_id=owner_id AND (i.performed_on IS NULL OR i.kind NOT IN ('ТО-1','ТО-2','ЭПБ','НО','УЗТ') OR i.kind='НО' AND s.group_code NOT IN ('ext','cui'))) THEN
  RAISE EXCEPTION 'A category stage requires an applicable performed inspection' USING ERRCODE='23514';
 END IF;
 IF EXISTS(SELECT s.group_code,coalesce(i.performed_on,s.reference_date) FROM equipment_inspection_category_stages s LEFT JOIN equipment_inspections i ON i.id=s.inspection_id
   WHERE s.equipment_id=owner_id GROUP BY s.group_code,coalesce(i.performed_on,s.reference_date) HAVING count(*)>1) THEN
  RAISE EXCEPTION 'Two category stages cannot share a group and date' USING ERRCODE='23514';
 END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER icat_diagnostic_calendar AFTER UPDATE OR DELETE ON equipment_inspections DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_inspection_category_calendar();
CREATE CONSTRAINT TRIGGER icat_stage_calendar AFTER INSERT OR UPDATE OR DELETE ON equipment_inspection_category_stages DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_inspection_category_calendar();
CREATE TRIGGER icat_observation_shape BEFORE INSERT OR UPDATE ON equipment_inspection_category_observations FOR EACH ROW EXECUTE FUNCTION guard_inspection_category_observation();
SQL);
        foreach (['equipment_inspection_category_stages', 'equipment_inspection_category_observations'] as $table) {
            DB::statement('CREATE TRIGGER a_icat_write_owner BEFORE INSERT OR UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION serialize_inspection_category_write()');
            DB::statement('CREATE TRIGGER icat_identity BEFORE UPDATE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION protect_inspection_category_identity()');
        }
    }

    public function down(): void
    {
        if (DB::table('equipment_inspection_category_stages')->exists()) {
            throw new RuntimeException('Cannot discard inspection category facts.');
        }
        DB::statement('DROP TRIGGER icat_diagnostic_calendar ON equipment_inspections');
        Schema::drop('equipment_inspection_category_observations');
        Schema::drop('equipment_inspection_category_stages');
        foreach (['serialize_inspection_category_write', 'protect_inspection_category_identity', 'guard_inspection_category_observation', 'guard_inspection_category_calendar'] as $function) {
            DB::statement('DROP FUNCTION '.$function.'()');
        }
    }
};
