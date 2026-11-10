<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $fields = json_decode(file_get_contents(resource_path('schemas/visual-inspections-v1.json')), true, flags: JSON_THROW_ON_ERROR)['fields'];
        Schema::create('equipment_visual_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->uuid('inspection_id')->nullable();
            $table->index('inspection_id');
            $table->date('reference_date')->nullable();
            $table->unique(['equipment_id', 'reference_date']);
            $table->foreign(['inspection_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_inspections')->restrictOnDelete();
            $table->unique(['id', 'equipment_id']);
            $table->unique(['equipment_id', 'inspection_id']);
            $table->date('measured_on')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at', 6)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz(6);
        });
        DB::statement('ALTER TABLE equipment_visual_stages ADD CHECK ((inspection_id IS NULL) = (reference_date IS NOT NULL))');
        DB::statement("ALTER TABLE equipment_visual_stages ADD CHECK (reference_date BETWEEN DATE '0001-01-01' AND DATE '9999-12-31')");
        DB::statement('ALTER TABLE equipment_visual_stages ADD CHECK ((verified_at IS NULL) = (verified_by IS NULL))');
        DB::statement('ALTER TABLE equipment_visual_stages ADD CHECK (verified_at IS NULL OR measured_on IS NOT NULL)');
        DB::statement("ALTER TABLE equipment_visual_stages ADD CHECK (measured_on BETWEEN DATE '0001-01-01' AND DATE '9999-12-31')");
        Schema::create('equipment_visual_observations', function (Blueprint $table) use ($fields) {
            $table->uuid('id')->primary();
            $table->uuid('equipment_id');
            $table->uuid('stage_id');
            $table->uuid('component_id');
            $table->index(['equipment_id', 'component_id']);
            $table->foreign(['stage_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_visual_stages')->restrictOnDelete();
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->unique(['stage_id', 'component_id']);
            foreach ($fields as $field) {
                match ($field['type']) {
                    'boolean' => $table->boolean($field['key'])->nullable(),
                    'number' => $table->double($field['key'])->nullable(),
                    default => $table->text($field['key'])->nullable(),
                };
            }
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz(6);
        });
        foreach ($fields as $field) {
            $key = $field['key'];
            if ($field['type'] === 'number') {
                DB::statement('ALTER TABLE equipment_visual_observations ADD CHECK ('.$key.' BETWEEN '.$field['min'].' AND '.$field['max']." AND $key NOT IN ('NaN'::float8,'Infinity'::float8,'-Infinity'::float8))");
            } elseif ($field['type'] === 'choice') {
                $options = implode(',', array_map(fn ($option) => "'$option'", array_keys($field['options'])));
                DB::statement('ALTER TABLE equipment_visual_observations ADD CHECK ('.$key.' IN ('.$options.'))');
            } elseif ($field['type'] === 'text') {
                DB::statement('ALTER TABLE equipment_visual_observations ADD CHECK (char_length('.$key.') <= '.$field['max_length'].')');
            }
        }
        DB::statement('ALTER TABLE equipment_visual_observations ADD CHECK (corrosion_present IS TRUE OR (corrosion_kind IS NULL AND severity IS NULL AND depth_mm IS NULL AND affected_area IS NULL AND area_unit IS NULL))');
        DB::statement("ALTER TABLE equipment_visual_observations ADD CHECK (area_unit <> 'percent' OR affected_area <= 100)");
        DB::statement('ALTER TABLE equipment_visual_observations ADD CHECK (affected_area IS NULL OR area_unit IS NOT NULL)');
        Schema::create('equipment_visual_insulation_overrides', function (Blueprint $table) {
            $table->uuid('component_id')->primary();
            $table->uuid('equipment_id')->index();
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->boolean('is_insulated');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz(6);
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION serialize_visual_inspection_write() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE equipment SET version=version WHERE id=COALESCE(NEW.equipment_id,OLD.equipment_id);
 RETURN COALESCE(NEW,OLD);
END; $$;
CREATE FUNCTION protect_visual_inspection_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' AND (to_jsonb(NEW)->'id' IS DISTINCT FROM to_jsonb(OLD)->'id' OR NEW.equipment_id<>OLD.equipment_id
    OR (TG_TABLE_NAME='equipment_visual_stages' AND (to_jsonb(NEW)->'inspection_id' IS DISTINCT FROM to_jsonb(OLD)->'inspection_id' OR to_jsonb(NEW)->'reference_date' IS DISTINCT FROM to_jsonb(OLD)->'reference_date'))
    OR (TG_TABLE_NAME='equipment_visual_insulation_overrides' AND to_jsonb(NEW)->'component_id' IS DISTINCT FROM to_jsonb(OLD)->'component_id')
    OR (TG_TABLE_NAME='equipment_visual_observations' AND (to_jsonb(NEW)->'stage_id' IS DISTINCT FROM to_jsonb(OLD)->'stage_id' OR to_jsonb(NEW)->'component_id' IS DISTINCT FROM to_jsonb(OLD)->'component_id'))) THEN
  RAISE EXCEPTION 'Visual inspection identity is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION protect_verified_visual_stage() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.verified_at IS NOT NULL THEN
  IF TG_OP='DELETE' OR (NEW.verified_at IS NOT NULL AND ROW(NEW.measured_on,NEW.verified_by,NEW.verified_at) IS DISTINCT FROM ROW(OLD.measured_on,OLD.verified_by,OLD.verified_at)) THEN
   RAISE EXCEPTION 'Unlock the verified visual stage first' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN COALESCE(NEW,OLD);
END; $$;
CREATE FUNCTION protect_verified_visual_observation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE checked_at timestamptz;
BEGIN
 SELECT verified_at INTO checked_at FROM equipment_visual_stages WHERE id=COALESCE(NEW.stage_id,OLD.stage_id) FOR UPDATE;
 IF checked_at IS NOT NULL THEN RAISE EXCEPTION 'Unlock the verified visual stage first' USING ERRCODE='23514'; END IF;
 RETURN COALESCE(NEW,OLD);
END; $$;
CREATE FUNCTION guard_visual_diagnostic_groups() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner_id uuid:=COALESCE(NEW.equipment_id,OLD.equipment_id);
BEGIN
 IF EXISTS(SELECT 1 FROM equipment_visual_stages s JOIN equipment_inspections i ON i.id=s.inspection_id
    WHERE s.equipment_id=owner_id AND (i.performed_on IS NULL OR i.kind NOT IN ('ТО-1','ТО-2','ЭПБ','НО'))) THEN
  RAISE EXCEPTION 'A visual stage requires a performed visual diagnostic' USING ERRCODE='23514';
 END IF;
 IF EXISTS(SELECT coalesce(i.performed_on,s.reference_date) FROM equipment_visual_stages s LEFT JOIN equipment_inspections i ON i.id=s.inspection_id
    WHERE s.equipment_id=owner_id GROUP BY coalesce(i.performed_on,s.reference_date) HAVING count(*)>1) THEN
  RAISE EXCEPTION 'Two stored visual stages cannot share the same diagnostic date' USING ERRCODE='23514';
 END IF;
 RETURN NULL;
END; $$;
CREATE FUNCTION protect_visual_diagnostic_date() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE equipment SET version=version WHERE id=COALESCE(NEW.equipment_id,OLD.equipment_id);
 IF TG_OP='UPDATE' AND ROW(NEW.performed_on,NEW.kind) IS DISTINCT FROM ROW(OLD.performed_on,OLD.kind)
    AND EXISTS(SELECT 1 FROM equipment_visual_stages WHERE inspection_id=OLD.id AND verified_at IS NOT NULL) THEN
  RAISE EXCEPTION 'Unlock the verified visual stage before changing the diagnostic date' USING ERRCODE='23514';
 END IF;
 RETURN COALESCE(NEW,OLD);
END; $$;
CREATE TRIGGER visual_stage_identity BEFORE UPDATE ON equipment_visual_stages FOR EACH ROW EXECUTE FUNCTION protect_visual_inspection_identity();
CREATE TRIGGER visual_observation_identity BEFORE UPDATE ON equipment_visual_observations FOR EACH ROW EXECUTE FUNCTION protect_visual_inspection_identity();
CREATE TRIGGER visual_override_identity BEFORE UPDATE ON equipment_visual_insulation_overrides FOR EACH ROW EXECUTE FUNCTION protect_visual_inspection_identity();
CREATE TRIGGER visual_stage_review BEFORE UPDATE OR DELETE ON equipment_visual_stages FOR EACH ROW EXECUTE FUNCTION protect_verified_visual_stage();
CREATE TRIGGER visual_observation_review BEFORE INSERT OR UPDATE OR DELETE ON equipment_visual_observations FOR EACH ROW EXECUTE FUNCTION protect_verified_visual_observation();
CREATE TRIGGER visual_diagnostic_review BEFORE UPDATE OR DELETE ON equipment_inspections FOR EACH ROW EXECUTE FUNCTION protect_visual_diagnostic_date();
CREATE CONSTRAINT TRIGGER visual_stage_diagnostic_group AFTER INSERT OR UPDATE OR DELETE ON equipment_visual_stages DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_visual_diagnostic_groups();
CREATE CONSTRAINT TRIGGER visual_diagnostic_group AFTER UPDATE OR DELETE ON equipment_inspections DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_visual_diagnostic_groups();
SQL);
        foreach (['equipment_visual_stages', 'equipment_visual_observations', 'equipment_visual_insulation_overrides'] as $table) {
            DB::statement('CREATE TRIGGER a_visual_write_owner BEFORE INSERT OR UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION serialize_visual_inspection_write()');
        }
    }

    public function down(): void
    {
        if (DB::table('equipment_visual_stages')->exists() || DB::table('equipment_visual_insulation_overrides')->exists()) {
            throw new RuntimeException('Cannot discard visual inspection facts.');
        }
        DB::statement('DROP TRIGGER visual_diagnostic_review ON equipment_inspections');
        DB::statement('DROP TRIGGER visual_diagnostic_group ON equipment_inspections');
        Schema::drop('equipment_visual_insulation_overrides');
        Schema::drop('equipment_visual_observations');
        Schema::drop('equipment_visual_stages');
        foreach (['serialize_visual_inspection_write', 'protect_visual_inspection_identity', 'protect_verified_visual_stage', 'protect_verified_visual_observation', 'guard_visual_diagnostic_groups', 'protect_visual_diagnostic_date'] as $function) {
            DB::statement('DROP FUNCTION '.$function.'()');
        }
    }
};
