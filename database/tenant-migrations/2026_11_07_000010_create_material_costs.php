<?php

use App\Domain\MaterialCostBaseline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legacy_code', 100)->unique();
            $table->string('label', 255);
            $table->string('origin', 20);
            $table->string('base_version', 100);
            $table->string('material_group', 10);
            $table->string('english_name', 255);
            $table->boolean('adapted')->default(false);
            $table->unsignedInteger('position');
            foreach (['base_values', 'base_raw', 'overrides', 'sources', 'checks'] as $column) {
                $table->jsonb($column)->default('{}');
            }
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
        });
        app(MaterialCostBaseline::class)->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('material_costs');
    }
};
