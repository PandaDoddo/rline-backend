<?php

use App\Domain\SubstanceBaseline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('substances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legacy_code', 100)->nullable()->unique();
            $table->string('label', 255);
            $table->string('origin', 20);
            $table->string('base_version', 100)->nullable();
            $table->jsonb('base_values')->default('{}');
            $table->jsonb('base_raw')->default('{}');
            $table->jsonb('base_flags')->default('{}');
            $table->jsonb('overrides')->default('{}');
            $table->jsonb('sources')->default('{}');
            $table->jsonb('checks')->default('{}');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX substances_active_name_unique ON substances (lower(label)) WHERE status = 'active'");
        app(SubstanceBaseline::class)->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('substances');
    }
};
