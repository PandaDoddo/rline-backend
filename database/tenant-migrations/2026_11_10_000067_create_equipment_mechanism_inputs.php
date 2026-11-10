<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = json_decode(file_get_contents(resource_path('catalogs/component-screening-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        if ($schema['version'] !== 'component-screening-v1') {
            throw new RuntimeException('Migration requires the immutable component screening v1 schema.');
        }
        Schema::create('equipment_mechanism_inputs', function (Blueprint $table) {
            $table->foreignUuid('equipment_id')->primary()->constrained('equipment')->restrictOnDelete();
            $table->boolean('vibration_present')->nullable();
            $table->double('hydrogen_partial_pressure_mpa')->nullable();
            $table->text('vibration_notes')->nullable();
            $table->text('hydrogen_notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz(6);
        });
        DB::statement('ALTER TABLE equipment_mechanism_inputs ADD CHECK (hydrogen_partial_pressure_mpa BETWEEN 0 AND 100000)');
        foreach (['vibration_notes', 'hydrogen_notes'] as $field) {
            DB::statement('ALTER TABLE equipment_mechanism_inputs ADD CHECK (char_length('.$field.')<=4000)');
        }
        Schema::create('equipment_component_mechanism_inputs', function (Blueprint $table) {
            $table->uuid('component_id')->primary();
            $table->uuid('equipment_id')->index();
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->boolean('vibration_excluded')->nullable();
            $table->boolean('tank_floor')->nullable();
            $table->text('vibration_notes')->nullable();
            $table->text('tank_floor_notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz(6);
        });
        foreach (['vibration_notes', 'tank_floor_notes'] as $field) {
            DB::statement('ALTER TABLE equipment_component_mechanism_inputs ADD CHECK (char_length('.$field.')<=4000)');
        }
        Schema::create('equipment_mechanism_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('equipment_id');
            $table->uuid('component_id');
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->string('mechanism_code', 32);
            $table->string('side', 16);
            $table->string('state', 16);
            $table->text('reason');
            $table->char('source_signature', 64);
            $table->unique(['component_id', 'mechanism_code', 'side'], 'mechanism_override_owner_unique');
            $table->index('equipment_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz(6);
        });
        DB::statement("ALTER TABLE equipment_mechanism_overrides ADD CHECK (state IN ('active','potential','inactive'))");
        DB::statement('ALTER TABLE equipment_mechanism_overrides ADD CHECK (char_length(btrim(reason)) BETWEEN 1 AND 5000)');
        DB::statement("ALTER TABLE equipment_mechanism_overrides ADD CHECK (source_signature ~ '^[0-9a-f]{64}$')");
        $pairs = [];
        $questionIds = [];
        $notePairs = [];
        foreach ($schema['mechanisms'] as $mechanism) {
            foreach ($mechanism['questions'] as $question) {
                $questionIds[] = $question['id'];
            }
            foreach ($mechanism['side_policy'] === 'process' ? ['internal', 'external'] : [$mechanism['side_policy']] as $side) {
                $pairs[] = "(mechanism_code='".$mechanism['id']."' AND side='".$side."')";
                foreach ($mechanism['questions'] as $question) {
                    $notePairs[] = "(question_id='".$question['id']."' AND side='".$side."')";
                }
            }
        }
        DB::statement('ALTER TABLE equipment_mechanism_overrides ADD CHECK ('.implode(' OR ', $pairs).')');
        Schema::create('equipment_mechanism_question_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('equipment_id');
            $table->uuid('component_id');
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->string('question_id', 64);
            $table->string('side', 16);
            $table->foreignUuid('flow_id')->nullable()->constrained('process_flow_nodes')->restrictOnDelete();
            $table->text('note');
            $table->index('equipment_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz(6);
        });
        DB::statement('ALTER TABLE equipment_mechanism_question_notes ADD CHECK (char_length(btrim(note)) BETWEEN 1 AND 4000)');
        DB::statement("ALTER TABLE equipment_mechanism_question_notes ADD CHECK (question_id IN ('".implode("','", array_unique($questionIds))."'))");
        DB::statement('ALTER TABLE equipment_mechanism_question_notes ADD CONSTRAINT mechanism_question_note_owner_unique UNIQUE NULLS NOT DISTINCT (component_id,question_id,side,flow_id)');
        DB::statement('ALTER TABLE equipment_mechanism_question_notes ADD CHECK ('.implode(' OR ', $notePairs).')');
        Schema::create('equipment_mechanism_reviews', function (Blueprint $table) {
            $table->uuid('component_id')->primary();
            $table->uuid('equipment_id')->index();
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->char('input_signature', 64);
            $table->unsignedBigInteger('verified_by');
            $table->timestampTz('verified_at', 6);
        });
        DB::statement("ALTER TABLE equipment_mechanism_reviews ADD CHECK (input_signature ~ '^[0-9a-f]{64}$')");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION serialize_equipment_mechanism_write() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE equipment SET version=version WHERE id=COALESCE(NEW.equipment_id,OLD.equipment_id);
 RETURN COALESCE(NEW,OLD);
END; $$;
CREATE FUNCTION protect_equipment_mechanism_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF ROW(NEW.equipment_id,to_jsonb(NEW)->'id',to_jsonb(NEW)->'component_id',to_jsonb(NEW)->'mechanism_code',to_jsonb(NEW)->'side',to_jsonb(NEW)->'question_id',to_jsonb(NEW)->'flow_id')
  IS DISTINCT FROM ROW(OLD.equipment_id,to_jsonb(OLD)->'id',to_jsonb(OLD)->'component_id',to_jsonb(OLD)->'mechanism_code',to_jsonb(OLD)->'side',to_jsonb(OLD)->'question_id',to_jsonb(OLD)->'flow_id') THEN
  RAISE EXCEPTION 'Mechanism input identity is immutable' USING ERRCODE='23514';
 END IF;
 IF TG_TABLE_NAME='equipment_mechanism_reviews' AND NEW IS DISTINCT FROM OLD THEN
  RAISE EXCEPTION 'Remove the previous review before confirming a new mechanism state' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION guard_equipment_mechanism_note_scope() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE invalid boolean:=false;
BEGIN
 IF TG_TABLE_NAME='equipment_mechanism_question_notes' THEN
  invalid:=NEW.flow_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM process_flow_nodes f JOIN equipment e ON e.installation_id=f.installation_id WHERE f.id=NEW.flow_id AND e.id=NEW.equipment_id AND f.kind='flow');
 ELSIF TG_TABLE_NAME='equipment' THEN
  SELECT EXISTS(SELECT 1 FROM equipment_mechanism_question_notes n JOIN process_flow_nodes f ON f.id=n.flow_id WHERE n.equipment_id=NEW.id AND f.installation_id<>NEW.installation_id) INTO invalid;
 ELSE
  SELECT EXISTS(SELECT 1 FROM equipment_mechanism_question_notes n JOIN equipment e ON e.id=n.equipment_id WHERE n.flow_id=NEW.id AND (e.installation_id<>NEW.installation_id OR NEW.kind<>'flow')) INTO invalid;
 END IF;
 IF invalid THEN RAISE EXCEPTION 'A mechanism question note requires a flow from the equipment installation' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER mechanism_note_scope AFTER INSERT OR UPDATE ON equipment_mechanism_question_notes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_equipment_mechanism_note_scope();
CREATE CONSTRAINT TRIGGER mechanism_note_equipment_scope AFTER UPDATE OF installation_id ON equipment DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_equipment_mechanism_note_scope();
CREATE CONSTRAINT TRIGGER mechanism_note_flow_scope AFTER UPDATE OF installation_id,kind ON process_flow_nodes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_equipment_mechanism_note_scope();
SQL);
        foreach (['equipment_mechanism_inputs', 'equipment_component_mechanism_inputs', 'equipment_mechanism_overrides', 'equipment_mechanism_question_notes', 'equipment_mechanism_reviews'] as $table) {
            DB::statement('CREATE TRIGGER a_mechanism_write_owner BEFORE INSERT OR UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION serialize_equipment_mechanism_write()');
            DB::statement('CREATE TRIGGER mechanism_identity BEFORE UPDATE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION protect_equipment_mechanism_identity()');
        }
    }

    public function down(): void
    {
        $tables = ['equipment_mechanism_reviews', 'equipment_mechanism_question_notes', 'equipment_mechanism_overrides', 'equipment_component_mechanism_inputs', 'equipment_mechanism_inputs'];
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot discard mechanism inputs, explanations or reviews.');
            }
        }
        DB::statement('DROP TRIGGER mechanism_note_equipment_scope ON equipment');
        DB::statement('DROP TRIGGER mechanism_note_flow_scope ON process_flow_nodes');
        foreach ($tables as $table) {
            Schema::drop($table);
        }
        DB::statement('DROP FUNCTION protect_equipment_mechanism_identity()');
        DB::statement('DROP FUNCTION serialize_equipment_mechanism_write()');
        DB::statement('DROP FUNCTION guard_equipment_mechanism_note_scope()');
    }
};
