<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_component_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->uuid('component_id');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->string('side', 20)->nullable();
            $table->string('joining_method')->nullable();
            $table->boolean('collar_present')->nullable();
            $table->string('thread_kind')->nullable();
            $table->string('thread_size')->nullable();
            $table->text('notes')->nullable();
            $table->string('source_reference', 1000)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz();
            $table->unique(['id', 'equipment_id']);
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->index(['component_id', 'position', 'id']);
        });
        DB::statement("ALTER TABLE equipment_component_connections ADD CHECK (status IN ('active','removed')), ADD CHECK (side IN ('coil')), ADD CHECK (position BETWEEN 0 AND 100000)");
        Schema::create('equipment_component_connection_targets', function (Blueprint $table) {
            $table->uuid('connection_id');
            $table->uuid('equipment_id');
            $table->uuid('target_component_id');
            $table->unsignedInteger('position');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['connection_id', 'target_component_id']);
            $table->foreign(['connection_id', 'equipment_id'], 'component_target_connection_owner_fk')->references(['id', 'equipment_id'])->on('equipment_component_connections')->restrictOnDelete();
            $table->foreign(['target_component_id', 'equipment_id'], 'component_target_component_owner_fk')->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
        });
        Schema::create('equipment_component_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->uuid('component_id');
            $table->string('kind', 20);
            $table->date('occurred_on')->nullable();
            $table->string('actor_name')->nullable();
            $table->text('description');
            $table->string('source_reference', 1000)->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->timestampTz('recorded_at', 6);
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->index(['component_id', 'occurred_on', 'id']);
        });
        DB::statement("ALTER TABLE equipment_component_history ADD CHECK (kind IN ('added','removed','restored','replaced','note','legacy')), ADD CHECK (length(btrim(description))>0)");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_component_connection_owner() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id IS DISTINCT FROM OLD.id OR NEW.component_id IS DISTINCT FROM OLD.component_id OR NEW.equipment_id IS DISTINCT FROM OLD.equipment_id THEN
  RAISE EXCEPTION 'Connection ownership is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER component_connection_owner BEFORE UPDATE ON equipment_component_connections
FOR EACH ROW EXECUTE FUNCTION guard_component_connection_owner();
CREATE FUNCTION guard_component_connection_target() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS (SELECT 1 FROM equipment_component_connections WHERE id=NEW.connection_id AND component_id=NEW.target_component_id) THEN
  RAISE EXCEPTION 'A connection cannot target its own component' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER component_connection_target BEFORE INSERT OR UPDATE ON equipment_component_connection_targets
FOR EACH ROW EXECUTE FUNCTION guard_component_connection_target();
CREATE FUNCTION reject_component_history_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Component history is append-only' USING ERRCODE='23514';
END $$;
CREATE TRIGGER component_history_immutable BEFORE UPDATE OR DELETE ON equipment_component_history
FOR EACH ROW EXECUTE FUNCTION reject_component_history_mutation();
ALTER VIEW equipment_component_cards RENAME TO equipment_component_specifications;
CREATE VIEW equipment_component_cards AS
SELECT s.*,COALESCE(g.items,'[]'::jsonb) connections,COALESCE(h.count,0) history_count
FROM equipment_component_specifications s
LEFT JOIN LATERAL (
 SELECT jsonb_agg(jsonb_build_object(
  'id',c.id,'status',c.status,'position',c.position,'side',c.side,'joining_method',c.joining_method,
  'collar_present',c.collar_present,'thread_kind',c.thread_kind,'thread_size',c.thread_size,
  'notes',c.notes,'source_reference',c.source_reference,'targets',COALESCE(t.ids,'[]'::jsonb),
  'complete',COALESCE(jsonb_array_length(t.ids)>0,FALSE) AND c.joining_method IS NOT NULL
 ) ORDER BY c.position,c.id) items
 FROM equipment_component_connections c
 LEFT JOIN LATERAL (SELECT jsonb_agg(target_component_id ORDER BY position,target_component_id) ids FROM equipment_component_connection_targets WHERE connection_id=c.id) t ON TRUE
 WHERE c.component_id=s.component_id
) g ON TRUE
LEFT JOIN LATERAL (
 SELECT count(*) count
 FROM equipment_component_history WHERE component_id=s.component_id
) h ON TRUE
SQL);
    }

    public function down(): void
    {
        if (DB::table('equipment_component_connections')->exists() || DB::table('equipment_component_history')->exists()) {
            throw new RuntimeException('Cannot remove populated component connections or historical facts.');
        }
        DB::statement('DROP VIEW equipment_component_cards');
        DB::statement('ALTER VIEW equipment_component_specifications RENAME TO equipment_component_cards');
        DB::statement('DROP TRIGGER component_history_immutable ON equipment_component_history');
        DB::statement('DROP FUNCTION reject_component_history_mutation()');
        DB::statement('DROP TRIGGER component_connection_target ON equipment_component_connection_targets');
        DB::statement('DROP FUNCTION guard_component_connection_target()');
        DB::statement('DROP TRIGGER component_connection_owner ON equipment_component_connections');
        DB::statement('DROP FUNCTION guard_component_connection_owner()');
        Schema::dropIfExists('equipment_component_connection_targets');
        Schema::dropIfExists('equipment_component_connections');
        Schema::dropIfExists('equipment_component_history');
    }
};
