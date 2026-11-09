<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['equipment_component_tube_geometries', 'equipment_component_coil_geometries', 'equipment_component_lens_geometries', 'equipment_component_expansion_joints'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->foreignUuid('component_id')->primary()->constrained('equipment_components')->restrictOnDelete();
                if ($name === 'equipment_component_tube_geometries') {
                    $table->string('tube_form')->nullable();
                    $table->unsignedInteger('tube_count')->nullable();
                } elseif ($name === 'equipment_component_coil_geometries') {
                    $table->string('coil_form')->nullable();
                } elseif ($name === 'equipment_component_lens_geometries') {
                    $table->double('outer_diameter_mm')->nullable();
                } else {
                    $table->string('joint_kind')->nullable();
                    $table->boolean('jacket_present')->nullable();
                }
                $table->timestampsTz();
            });
        }
        DB::statement("ALTER TABLE equipment_component_tube_geometries ADD CHECK (tube_form IN ('прямые','U-образные')), ADD CHECK (tube_count BETWEEN 1 AND 1000000)");
        DB::statement("ALTER TABLE equipment_component_coil_geometries ADD CHECK (coil_form IN ('спиральный','винтовой','зигзагообразный','секционный'))");
        DB::statement('ALTER TABLE equipment_component_lens_geometries ADD CHECK (outer_diameter_mm>0 AND outer_diameter_mm<=100000000)');
        DB::statement("ALTER TABLE equipment_component_expansion_joints ADD CHECK (joint_kind IN ('осевой','угловой'))");
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (DB::table($name)->exists()) {
                throw new RuntimeException('Cannot remove populated component thermal geometry: '.$name);
            }
        }
        foreach (self::TABLES as $name) {
            Schema::dropIfExists($name);
        }
    }
};
