<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_component_constructions', function (Blueprint $table) {
            $table->foreignUuid('component_id')->primary()->constrained('equipment_components')->restrictOnDelete();
            foreach (['mark', 'joining_method', 'manufacturing_method', 'manufacturing_standard', 'execution', 'assembly_kind', 'line_number', 'service_name', 'seam_type'] as $field) {
                $table->string($field)->nullable();
            }
            $table->double('nominal_pressure_mpa')->nullable();
            $table->boolean('is_primary')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE equipment_component_constructions ADD CHECK (assembly_kind IN ('mono','courses','assembly','coil','spools'))");
        DB::statement("ALTER TABLE equipment_component_constructions ADD CHECK (seam_type IN ('seamless','welded'))");
        DB::statement("ALTER TABLE equipment_component_constructions ADD CHECK (nominal_pressure_mpa BETWEEN 0 AND 10000 AND nominal_pressure_mpa NOT IN ('NaN'::float8,'Infinity'::float8,'-Infinity'::float8))");
    }

    public function down(): void
    {
        if (DB::table('equipment_component_constructions')->exists()) {
            throw new RuntimeException('Cannot remove populated component construction facts.');
        }
        Schema::dropIfExists('equipment_component_constructions');
    }
};
