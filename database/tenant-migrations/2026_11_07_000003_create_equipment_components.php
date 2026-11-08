<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->unsignedInteger('next_component_number')->default(1);
        });
        Schema::create('equipment_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('code', 40);
            $table->string('kind', 30);
            $table->string('name', 255);
            $table->boolean('is_coil')->default(false);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('next_element_number')->default(1);
            $table->string('material', 255)->nullable();
            $table->decimal('diameter_mm', 14, 4)->nullable();
            $table->decimal('length_mm', 14, 4)->nullable();
            $table->decimal('nominal_thickness_mm', 14, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['equipment_id', 'code']);
            $table->index(['equipment_id', 'parent_id', 'position']);
        });
        Schema::table('equipment_components', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('equipment_components')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_components');
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn('next_component_number');
        });
    }
};
