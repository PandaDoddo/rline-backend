<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'equipment_component_end_connections' => ['far_end_kind', 'thread_kind', 'thread_size', 'instrument_kind'],
        'equipment_component_boss_geometries' => ['design_type'],
        'equipment_component_valve_details' => ['valve_class', 'valve_kind', 'drive_kind'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name => $fields) {
            Schema::create($name, function (Blueprint $table) use ($name, $fields) {
                $table->foreignUuid('component_id')->primary()->constrained('equipment_components')->restrictOnDelete();
                foreach ($fields as $field) {
                    $table->string($field)->nullable();
                }
                if ($name === 'equipment_component_end_connections') {
                    $table->boolean('instrument_connection')->nullable();
                }
                if ($name === 'equipment_component_boss_geometries') {
                    $table->double('height_mm')->nullable();
                }
                $table->timestampsTz();
            });
        }
        DB::statement('ALTER TABLE equipment_component_boss_geometries ADD CHECK (height_mm>0 AND height_mm<=100000000)');
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $name) {
            if (DB::table($name)->exists()) {
                throw new RuntimeException('Cannot remove populated component details: '.$name);
            }
        }
        foreach (array_keys(self::TABLES) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
