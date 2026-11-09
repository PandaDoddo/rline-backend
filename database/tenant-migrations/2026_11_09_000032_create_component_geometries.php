<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'equipment_component_head_geometries' => [
            'text' => ['shape', 'flanging'],
            'positive' => ['height_mm', 'outer_diameter_mm'],
        ],
        'equipment_component_fitting_geometries' => [
            'text' => ['bend_radius_designation', 'reducer_form', 'collar_material', 'collar_hole_thread', 'collar_seam_type'],
            'positive' => ['large_diameter_mm', 'large_thickness_mm', 'small_diameter_mm', 'small_thickness_mm', 'main_diameter_mm', 'main_thickness_mm', 'branch_diameter_mm', 'branch_thickness_mm', 'collar_diameter_mm', 'collar_thickness_mm'],
            'boolean' => ['collar_present', 'collar_hole_present'],
            'angle' => ['bend_angle_deg'],
        ],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name => $groups) {
            Schema::create($name, function (Blueprint $table) use ($groups) {
                $table->foreignUuid('component_id')->primary()->constrained('equipment_components')->restrictOnDelete();
                foreach ($groups as $type => $fields) {
                    foreach ($fields as $field) {
                        match ($type) {
                            'text' => $table->string($field)->nullable(),
                            'boolean' => $table->boolean($field)->nullable(),
                            default => $table->double($field)->nullable(),
                        };
                    }
                }
                $table->timestampsTz();
            });
            foreach ($groups['positive'] ?? [] as $field) {
                DB::statement('ALTER TABLE '.$name.' ADD CHECK ('.$field.'>0 AND '.$field.'<=100000000)');
            }
            foreach ($groups['angle'] ?? [] as $field) {
                DB::statement('ALTER TABLE '.$name.' ADD CHECK ('.$field.' BETWEEN 0 AND 360)');
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $name) {
            if (DB::table($name)->exists()) {
                throw new RuntimeException('Cannot remove populated component geometry: '.$name);
            }
        }
        foreach (array_keys(self::TABLES) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
