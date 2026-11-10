<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('substance_properties', fn (Blueprint $table) => $table->text('idlh_unit')->nullable());
        DB::statement("ALTER TABLE substance_properties ADD CONSTRAINT substance_value_idlh_unit CHECK (idlh_unit IN ('ppm','mg/m3'))");
        DB::table('substance_property_fields')->insert(['field_key' => 'idlhUnit', 'column_name' => 'idlh_unit', 'value_type' => 'choice']);
        $this->view(true);
    }

    private function view(bool $extended): void
    {
        $definition = file_get_contents(resource_path('schemas/substance-cards-'.($extended ? 'v2' : 'v1').'.sql'));
        DB::statement('DROP VIEW substance_cards');
        DB::unprepared($definition);
    }

    public function down(): void
    {
        if (DB::table('substance_property_annotations')->where('field_key', 'idlhUnit')->exists()) {
            throw new RuntimeException('IDLH units contain tenant data; export before rollback.');
        }
        $this->view(false);
        DB::table('substance_property_fields')->where('field_key', 'idlhUnit')->delete();
        Schema::table('substance_properties', fn (Blueprint $table) => $table->dropColumn('idlh_unit'));
    }
};
