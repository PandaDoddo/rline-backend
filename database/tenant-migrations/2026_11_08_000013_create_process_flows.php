<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installations', function (Blueprint $table) {
            $table->unsignedInteger('flow_structure_version')->default(1);
            $table->jsonb('flow_setup')->nullable();
        });
        Schema::create('process_flow_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('installation_id')->constrained('installations')->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('kind', 10);
            $table->string('category', 20);
            $table->string('name', 255);
            $table->string('medium_kind', 20)->default('fluid');
            $table->string('basis', 10)->default('mass');
            $table->jsonb('attributes')->default('{}');
            $table->jsonb('sources')->default('{}');
            $table->jsonb('checks')->default('{}');
            $table->jsonb('accepted_calculation')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz();
            $table->index(['installation_id', 'parent_id', 'status']);
        });
        Schema::table('process_flow_nodes', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('process_flow_nodes')->restrictOnDelete());
        Schema::create('process_flow_composition', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('flow_id')->constrained('process_flow_nodes')->cascadeOnDelete();
            $table->foreignUuid('substance_id')->nullable()->constrained('substances')->restrictOnDelete();
            $table->string('role', 15);
            $table->string('name', 255)->nullable();
            $table->double('amount')->nullable();
            $table->string('unit', 20)->nullable();
            $table->boolean('plus')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
            $table->index(['flow_id', 'role', 'position']);
        });
        Schema::create('process_flow_calculations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('flow_id')->constrained('process_flow_nodes')->restrictOnDelete();
            $table->string('signature', 64);
            $table->string('engine_version', 50);
            $table->jsonb('inputs');
            $table->jsonb('result');
            $table->unsignedBigInteger('created_by');
            $table->timestampTz('created_at');
            $table->index(['flow_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_flow_calculations');
        Schema::dropIfExists('process_flow_composition');
        Schema::dropIfExists('process_flow_nodes');
        Schema::table('installations', fn (Blueprint $table) => $table->dropColumn(['flow_structure_version', 'flow_setup']));
    }
};
