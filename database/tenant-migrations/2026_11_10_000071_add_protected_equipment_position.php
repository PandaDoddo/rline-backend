<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', fn (Blueprint $table) => $table->string('protected_equipment_position')->nullable());
        DB::statement("ALTER TABLE equipment ADD CONSTRAINT protected_position_ppk_only CHECK (protected_equipment_position IS NULL OR profile_code='ppk')");
        DB::table('equipment_passport_fields')->insert(['key' => 'protected_equipment_position', 'owner_table' => 'equipment', 'data_type' => 'text']);
        DB::statement('ALTER VIEW equipment_passports RENAME TO equipment_passports_v3');
        DB::statement("CREATE VIEW equipment_passports AS SELECT p.equipment_id, p.passport || jsonb_strip_nulls(jsonb_build_object('protected_equipment_position',e.protected_equipment_position)) AS passport,p.qualifiers,p.checks FROM equipment_passports_v3 p JOIN equipment e ON e.id=p.equipment_id");
    }

    public function down(): void
    {
        if (DB::table('equipment')->whereNotNull('protected_equipment_position')->exists() || DB::table('equipment_passport_annotations')->where('field_key', 'protected_equipment_position')->exists()) {
            throw new RuntimeException('Cannot discard known protected equipment positions.');
        }
        DB::statement('DROP VIEW equipment_passports');
        DB::statement('ALTER VIEW equipment_passports_v3 RENAME TO equipment_passports');
        DB::table('equipment_passport_fields')->where('key', 'protected_equipment_position')->delete();
        DB::statement('ALTER TABLE equipment DROP CONSTRAINT protected_position_ppk_only');
        Schema::table('equipment', fn (Blueprint $table) => $table->dropColumn('protected_equipment_position'));
    }
};
