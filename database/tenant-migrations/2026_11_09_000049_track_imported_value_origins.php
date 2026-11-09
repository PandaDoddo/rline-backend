<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE dataset_import_equipment ADD CONSTRAINT dataset_import_equipment_batch_unique UNIQUE(equipment_id,batch_id)');
        DB::statement('ALTER TABLE equipment_thickness_values ADD CONSTRAINT thickness_values_owner_unique UNIQUE(id,equipment_id)');
        Schema::create('dataset_import_passport_origins', function (Blueprint $table) {
            $table->uuid('equipment_id');
            $table->uuid('batch_id');
            $table->string('field_key', 100);
            $table->foreign('field_key')->references('key')->on('equipment_passport_fields')->restrictOnDelete();
            // Immutable scalar evidence, never the editable passport source.
            $table->jsonb('imported_value');
            $table->primary(['equipment_id', 'field_key']);
        });
        Schema::create('dataset_import_point_origins', function (Blueprint $table) {
            $table->unsignedBigInteger('measurement_id');
            $table->unsignedSmallInteger('point_number');
            $table->uuid('equipment_id');
            $table->uuid('batch_id');
            $table->string('kind', 30);
            $table->double('imported_thickness_mm')->nullable();
            $table->primary(['measurement_id', 'point_number']);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE dataset_import_passport_origins ADD CHECK (jsonb_typeof(imported_value) IN ('number','string','boolean','null'));
ALTER TABLE dataset_import_point_origins
 ADD CHECK (kind IN ('stored_snapshot','demo_generated')),
 ADD FOREIGN KEY(measurement_id,point_number) REFERENCES equipment_thickness_points(measurement_id,point_number),
 ADD FOREIGN KEY(measurement_id,equipment_id) REFERENCES equipment_thickness_values(id,equipment_id);
ALTER TABLE dataset_import_passport_origins ADD FOREIGN KEY(equipment_id,batch_id)
 REFERENCES dataset_import_equipment(equipment_id,batch_id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE dataset_import_point_origins ADD FOREIGN KEY(equipment_id,batch_id)
 REFERENCES dataset_import_equipment(equipment_id,batch_id) DEFERRABLE INITIALLY DEFERRED;
CREATE TRIGGER dataset_import_passport_origin_immutable BEFORE UPDATE OR DELETE ON dataset_import_passport_origins
 FOR EACH ROW EXECUTE FUNCTION reject_dataset_import_receipt_mutation();
CREATE TRIGGER dataset_import_point_origin_immutable BEFORE UPDATE OR DELETE ON dataset_import_point_origins
 FOR EACH ROW EXECUTE FUNCTION reject_dataset_import_receipt_mutation();
CREATE FUNCTION guard_thickness_point_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF ROW(NEW.id,NEW.measurement_id,NEW.point_number) IS DISTINCT FROM ROW(OLD.id,OLD.measurement_id,OLD.point_number) THEN
  RAISE EXCEPTION 'Thickness point identity is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER thickness_point_identity BEFORE UPDATE ON equipment_thickness_points
 FOR EACH ROW EXECUTE FUNCTION guard_thickness_point_identity();
CREATE OR REPLACE VIEW equipment_thickness_measurements AS
SELECT m.id,m.equipment_id,m.stage_id,m.cml_id,m.created_at,m.updated_at,
 COALESCE(p.points,'[]'::jsonb) points,COALESCE(p.point_origins,'[]'::jsonb) point_origins
FROM equipment_thickness_values m LEFT JOIN LATERAL (
 SELECT jsonb_agg(p.thickness_mm ORDER BY p.point_number) points,
 jsonb_agg(CASE WHEN o.measurement_id IS NOT NULL AND p.thickness_mm IS NOT DISTINCT FROM o.imported_thickness_mm
 THEN o.kind ELSE 'manual' END ORDER BY p.point_number) point_origins
 FROM equipment_thickness_points p LEFT JOIN dataset_import_point_origins o
 ON o.measurement_id=p.measurement_id AND o.point_number=p.point_number WHERE p.measurement_id=m.id
) p ON TRUE;
SQL);
    }

    public function down(): void
    {
        if (DB::table('dataset_import_passport_origins')->exists() || DB::table('dataset_import_point_origins')->exists()) {
            throw new RuntimeException('Cannot discard imported value evidence.');
        }
        DB::statement('DROP VIEW equipment_thickness_measurements');
        DB::statement("CREATE VIEW equipment_thickness_measurements AS SELECT m.id,m.equipment_id,m.stage_id,m.cml_id,m.created_at,m.updated_at,COALESCE(p.points,'[]'::jsonb) points FROM equipment_thickness_values m LEFT JOIN LATERAL (SELECT jsonb_agg(thickness_mm ORDER BY point_number) points FROM equipment_thickness_points WHERE measurement_id=m.id) p ON TRUE");
        DB::statement('DROP TRIGGER thickness_point_identity ON equipment_thickness_points');
        DB::statement('DROP FUNCTION guard_thickness_point_identity()');
        Schema::drop('dataset_import_point_origins');
        Schema::drop('dataset_import_passport_origins');
        DB::statement('ALTER TABLE equipment_thickness_values DROP CONSTRAINT thickness_values_owner_unique');
        DB::statement('ALTER TABLE dataset_import_equipment DROP CONSTRAINT dataset_import_equipment_batch_unique');
    }
};
