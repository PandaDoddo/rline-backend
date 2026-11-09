<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_component_assemblies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('equipment_id');
            $table->uuid('component_id');
            $table->string('code', 40);
            $table->string('kind', 60);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->uuid('after_component_id')->nullable();
            $table->double('nominal_pressure_mpa')->nullable();
            $table->string('joint_kind')->nullable();
            $table->boolean('jacket_present')->nullable();
            $table->string('manufacturing_standard')->nullable();
            $table->text('notes')->nullable();
            $table->string('source_reference', 1000)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz();
            $table->unique(['id', 'equipment_id']);
            $table->unique(['component_id', 'code']);
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->foreign(['after_component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            $table->index(['component_id', 'position', 'id']);
        });
        Schema::create('equipment_component_assembly_members', function (Blueprint $table) {
            $table->uuid('assembly_id');
            $table->uuid('equipment_id');
            $table->uuid('member_component_id');
            $table->unsignedInteger('position');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['assembly_id', 'member_component_id']);
            $table->foreign(['assembly_id', 'equipment_id'], 'assembly_member_owner_fk')->references(['id', 'equipment_id'])->on('equipment_component_assemblies')->restrictOnDelete();
            $table->foreign(['member_component_id', 'equipment_id'], 'assembly_member_component_fk')->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE equipment_component_assemblies
 ADD CHECK (kind='lens_expansion_joint'), ADD CHECK (status IN ('active','removed')),
 ADD CHECK (position BETWEEN 0 AND 100000), ADD CHECK (length(btrim(code))>0),
 ADD CHECK (joint_kind IN ('осевой','угловой')),
 ADD CHECK (nominal_pressure_mpa>=0 AND nominal_pressure_mpa<'Infinity'::float8);
CREATE FUNCTION guard_component_assembly() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' AND (OLD.id,OLD.equipment_id,OLD.component_id,OLD.code,OLD.kind) IS DISTINCT FROM (NEW.id,NEW.equipment_id,NEW.component_id,NEW.code,NEW.kind) THEN
  RAISE EXCEPTION 'Assembly identity is immutable' USING ERRCODE='23514';
 END IF;
 IF NEW.after_component_id IS NOT NULL AND NOT EXISTS (
  WITH RECURSIVE descendants AS (
   SELECT id FROM equipment_components WHERE parent_id=NEW.component_id
   UNION ALL SELECT c.id FROM equipment_components c JOIN descendants d ON c.parent_id=d.id
  ) SELECT 1 FROM descendants WHERE id=NEW.after_component_id
 ) THEN
  RAISE EXCEPTION 'Assembly predecessor must belong to the owner subtree' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER component_assembly_guard BEFORE INSERT OR UPDATE ON equipment_component_assemblies
 FOR EACH ROW EXECUTE FUNCTION guard_component_assembly();
CREATE FUNCTION guard_component_assembly_member() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner_id uuid; predecessor_id uuid;
BEGIN
 SELECT component_id,after_component_id INTO owner_id,predecessor_id FROM equipment_component_assemblies WHERE id=NEW.assembly_id;
 IF NEW.member_component_id=predecessor_id OR NOT EXISTS (
  WITH RECURSIVE descendants AS (
   SELECT id FROM equipment_components WHERE parent_id=owner_id
   UNION ALL SELECT c.id FROM equipment_components c JOIN descendants d ON c.parent_id=d.id
  ) SELECT 1 FROM descendants WHERE id=NEW.member_component_id
 ) THEN
  RAISE EXCEPTION 'Assembly member must be a descendant distinct from the predecessor' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER component_assembly_member_guard BEFORE INSERT OR UPDATE ON equipment_component_assembly_members
 FOR EACH ROW EXECUTE FUNCTION guard_component_assembly_member();
ALTER VIEW equipment_component_cards RENAME TO equipment_component_graph_cards;
CREATE VIEW equipment_component_cards AS
SELECT c.*,COALESCE(a.items,'[]'::jsonb) assemblies
FROM equipment_component_graph_cards c
LEFT JOIN LATERAL (
 SELECT jsonb_agg(jsonb_build_object(
  'id',a.id,'code',a.code,'kind',a.kind,'status',a.status,'position',a.position,'after_component_id',a.after_component_id,
  'nominal_pressure_mpa',a.nominal_pressure_mpa,'joint_kind',a.joint_kind,'jacket_present',a.jacket_present,
  'manufacturing_standard',a.manufacturing_standard,'notes',a.notes,'source_reference',a.source_reference,
  'members',COALESCE(m.ids,'[]'::jsonb)
 ) ORDER BY a.position,a.id) items
 FROM equipment_component_assemblies a
 LEFT JOIN LATERAL (
  SELECT jsonb_agg(member_component_id ORDER BY position,member_component_id) ids
  FROM equipment_component_assembly_members WHERE assembly_id=a.id
 ) m ON TRUE WHERE a.component_id=c.component_id
) a ON TRUE
SQL);
    }

    public function down(): void
    {
        if (DB::table('equipment_component_assemblies')->exists()) {
            throw new RuntimeException('Cannot remove populated component assemblies.');
        }
        DB::statement('DROP VIEW equipment_component_cards');
        DB::statement('ALTER VIEW equipment_component_graph_cards RENAME TO equipment_component_cards');
        DB::statement('DROP TRIGGER component_assembly_member_guard ON equipment_component_assembly_members');
        DB::statement('DROP FUNCTION guard_component_assembly_member()');
        DB::statement('DROP TRIGGER component_assembly_guard ON equipment_component_assemblies');
        DB::statement('DROP FUNCTION guard_component_assembly()');
        Schema::dropIfExists('equipment_component_assembly_members');
        Schema::dropIfExists('equipment_component_assemblies');
    }
};
