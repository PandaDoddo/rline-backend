<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_component_constructions', fn (Blueprint $table) => $table->string('flange_type')->nullable());
        Schema::create('equipment_component_shell_geometries', function (Blueprint $table) {
            $table->foreignUuid('component_id')->primary()->constrained('equipment_components')->restrictOnDelete();
            $table->string('shell_form')->nullable();
            $table->double('small_diameter_mm')->nullable();
            $table->double('small_thickness_mm')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE equipment_component_shell_geometries ADD CHECK (shell_form IN ('цилиндрический','конический')), ADD CHECK (small_diameter_mm>0 AND small_diameter_mm<=100000000), ADD CHECK (small_thickness_mm>0 AND small_thickness_mm<=100000000)");
    }

    public function down(): void
    {
        if (DB::table('equipment_component_shell_geometries')->exists() || DB::table('equipment_component_constructions')->whereNotNull('flange_type')->exists()) {
            throw new RuntimeException('Cannot remove populated shell geometry or flange construction.');
        }
        Schema::dropIfExists('equipment_component_shell_geometries');
        Schema::table('equipment_component_constructions', fn (Blueprint $table) => $table->dropColumn('flange_type'));
    }
};
