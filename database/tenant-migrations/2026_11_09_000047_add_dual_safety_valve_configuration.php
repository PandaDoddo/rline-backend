<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_constructions', fn (Blueprint $table) => $table->string('relief_valve_dual')->nullable());
        DB::statement("ALTER TABLE equipment_constructions ADD CHECK (relief_valve_dual IN ('да','нет'))");
        DB::statement("ALTER TABLE equipment_constructions ADD CHECK (relief_valve_dual IS DISTINCT FROM 'да' OR relief_valve_present IS NOT DISTINCT FROM 'да')");
        DB::table('equipment_passport_fields')->insert(['key' => 'relief_valve_dual', 'owner_table' => 'equipment_constructions', 'data_type' => 'text']);
    }

    public function down(): void
    {
        if (DB::table('equipment_constructions')->whereNotNull('relief_valve_dual')->exists() || DB::table('equipment_passport_annotations')->where('field_key', 'relief_valve_dual')->exists()) {
            throw new RuntimeException('Cannot discard a known safety valve configuration.');
        }
        DB::table('equipment_passport_fields')->where('key', 'relief_valve_dual')->delete();
        Schema::table('equipment_constructions', fn (Blueprint $table) => $table->dropColumn('relief_valve_dual'));
    }
};
