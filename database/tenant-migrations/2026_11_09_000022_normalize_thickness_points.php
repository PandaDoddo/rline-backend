<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE equipment_thickness_values IN ACCESS EXCLUSIVE MODE');
        foreach (['equipment_cmls', 'equipment_thickness_stages', 'equipment_inspections'] as $table) {
            DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT '.$table.'_owner_unique UNIQUE (id,equipment_id)');
        }
        DB::statement('ALTER TABLE equipment_cmls ADD CONSTRAINT equipment_cmls_component_owner_fk FOREIGN KEY (component_id,equipment_id) REFERENCES equipment_components(id,equipment_id)');
        DB::statement('ALTER TABLE equipment_thickness_stages ADD CONSTRAINT equipment_thickness_stages_inspection_owner_fk FOREIGN KEY (inspection_id,equipment_id) REFERENCES equipment_inspections(id,equipment_id)');
        Schema::table('equipment_thickness_values', fn (Blueprint $table) => $table->uuid('equipment_id')->nullable());
        DB::statement('UPDATE equipment_thickness_values m SET equipment_id=s.equipment_id FROM equipment_thickness_stages s WHERE s.id=m.stage_id');
        DB::statement('ALTER TABLE equipment_thickness_values ALTER COLUMN equipment_id SET NOT NULL');
        DB::statement('ALTER TABLE equipment_thickness_values ADD CONSTRAINT thickness_values_stage_owner_fk FOREIGN KEY (stage_id,equipment_id) REFERENCES equipment_thickness_stages(id,equipment_id)');
        DB::statement('ALTER TABLE equipment_thickness_values ADD CONSTRAINT thickness_values_cml_owner_fk FOREIGN KEY (cml_id,equipment_id) REFERENCES equipment_cmls(id,equipment_id)');
        Schema::create('equipment_thickness_points', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('measurement_id');
            $table->foreign('measurement_id')->references('id')->on('equipment_thickness_values')->restrictOnDelete();
            $table->unsignedSmallInteger('point_number');
            $table->double('thickness_mm')->nullable();
            $table->unique(['measurement_id', 'point_number']);
        });
        DB::statement('ALTER TABLE equipment_thickness_points ADD CHECK (point_number BETWEEN 1 AND 100)');
        DB::statement("ALTER TABLE equipment_thickness_points ADD CHECK (thickness_mm BETWEEN 0 AND 100000 AND thickness_mm NOT IN ('NaN'::float8,'Infinity'::float8,'-Infinity'::float8))");
        DB::statement(<<<'SQL'
INSERT INTO equipment_thickness_points(measurement_id,point_number,thickness_mm)
SELECT m.id,p.ordinality,CASE WHEN p.value='null'::jsonb THEN NULL ELSE (p.value#>>'{}')::float8 END
FROM equipment_thickness_values m CROSS JOIN LATERAL jsonb_array_elements(m.points) WITH ORDINALITY p(value,ordinality)
SQL);
        DB::statement(<<<'SQL'
CREATE VIEW equipment_thickness_measurements AS
SELECT m.*,COALESCE(p.points,'[]'::jsonb) measured_points FROM equipment_thickness_values m
LEFT JOIN LATERAL (SELECT jsonb_agg(thickness_mm ORDER BY point_number) points FROM equipment_thickness_points WHERE measurement_id=m.id) p ON TRUE
SQL);
        if (DB::table('equipment_thickness_measurements')->whereRaw('points IS DISTINCT FROM measured_points')->exists()) {
            throw new RuntimeException('Thickness point normalization comparison failed.');
        }
        // Recreate without m.* so dropping the source array cannot invalidate the DTO view.
        DB::statement('DROP VIEW equipment_thickness_measurements');
        DB::statement(<<<'SQL'
CREATE VIEW equipment_thickness_measurements AS
SELECT m.id,m.equipment_id,m.stage_id,m.cml_id,m.created_at,m.updated_at,COALESCE(p.points,'[]'::jsonb) points
FROM equipment_thickness_values m LEFT JOIN LATERAL (
 SELECT jsonb_agg(thickness_mm ORDER BY point_number) points FROM equipment_thickness_points WHERE measurement_id=m.id
) p ON TRUE
SQL);
        Schema::table('equipment_thickness_values', fn (Blueprint $table) => $table->dropColumn('points'));
    }

    public function down(): void
    {
        Schema::table('equipment_thickness_values', fn (Blueprint $table) => $table->jsonb('points')->nullable());
        DB::statement('UPDATE equipment_thickness_values m SET points=p.points FROM equipment_thickness_measurements p WHERE p.id=m.id');
        DB::statement('ALTER TABLE equipment_thickness_values ALTER COLUMN points SET NOT NULL');
        DB::statement('DROP VIEW equipment_thickness_measurements');
        Schema::dropIfExists('equipment_thickness_points');
        foreach (['thickness_values_stage_owner_fk', 'thickness_values_cml_owner_fk'] as $constraint) {
            DB::statement('ALTER TABLE equipment_thickness_values DROP CONSTRAINT '.$constraint);
        }
        Schema::table('equipment_thickness_values', fn (Blueprint $table) => $table->dropColumn('equipment_id'));
        DB::statement('ALTER TABLE equipment_cmls DROP CONSTRAINT equipment_cmls_component_owner_fk');
        DB::statement('ALTER TABLE equipment_thickness_stages DROP CONSTRAINT equipment_thickness_stages_inspection_owner_fk');
        foreach (['equipment_cmls', 'equipment_thickness_stages', 'equipment_inspections'] as $table) {
            DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT '.$table.'_owner_unique');
        }
    }
};
