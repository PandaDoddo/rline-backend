<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_components', fn (Blueprint $table) => $table->string('flow_space', 10)->nullable());
        Schema::create('equipment_flow_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignUuid('component_id')->nullable()->constrained('equipment_components')->restrictOnDelete();
            $table->string('scope', 15);
            $table->string('side', 15);
            $table->string('mode', 10);
            $table->text('notes')->nullable();
            $table->jsonb('check')->nullable();
            $table->timestampsTz();
            $table->index('equipment_id');
        });
        DB::connection('tenant')->statement('CREATE UNIQUE INDEX equipment_flow_target_unique ON equipment_flow_contacts (equipment_id, scope, component_id, side) NULLS NOT DISTINCT');
        Schema::create('equipment_flow_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->constrained('equipment_flow_contacts')->cascadeOnDelete();
            $table->foreignUuid('flow_id')->constrained('process_flow_nodes')->restrictOnDelete();
            $table->double('share_percent')->nullable();
            $table->unsignedInteger('accepted_version');
            $table->text('notes')->nullable();
            $table->unique(['contact_id', 'flow_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_flow_links');
        Schema::dropIfExists('equipment_flow_contacts');
        Schema::table('equipment_components', fn (Blueprint $table) => $table->dropColumn('flow_space'));
    }
};
