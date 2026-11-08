<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_components', function (Blueprint $table) {
            $table->unsignedInteger('next_cml_number')->default(1);
            $table->decimal('rejection_passport_mm', 14, 4)->nullable();
            $table->decimal('rejection_epb_mm', 14, 4)->nullable();
        });
        Schema::create('equipment_cmls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->foreignUuid('component_id')->constrained('equipment_components')->restrictOnDelete();
            $table->string('code', 60);
            $table->unsignedSmallInteger('point_count');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['equipment_id', 'code']);
        });
        Schema::create('equipment_thickness_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->foreignUuid('inspection_id')->unique()->constrained('equipment_inspections')->restrictOnDelete();
            $table->date('measured_on')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('equipment_thickness_values', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('stage_id')->constrained('equipment_thickness_stages')->restrictOnDelete();
            $table->foreignUuid('cml_id')->constrained('equipment_cmls')->restrictOnDelete();
            $table->jsonb('points');
            $table->timestampsTz();
            $table->unique(['stage_id', 'cml_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_thickness_values');
        Schema::dropIfExists('equipment_thickness_stages');
        Schema::dropIfExists('equipment_cmls');
        Schema::table('equipment_components', function (Blueprint $table) {
            $table->dropColumn(['next_cml_number', 'rejection_passport_mm', 'rejection_epb_mm']);
        });
    }
};
