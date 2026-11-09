<?php

use App\Modules\Catalogs\Infrastructure\SteelGradeBaseline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steel_grades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legacy_code', 100)->nullable()->unique();
            $table->string('label', 255);
            $table->string('origin', 20);
            $table->string('base_version', 100)->nullable();
            foreach (['base_values', 'base_raw', 'overrides', 'sources', 'checks'] as $column) {
                $table->jsonb($column)->default('{}');
            }
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX steel_grades_active_name_unique ON steel_grades (lower(label)) WHERE status = 'active'");
        app(SteelGradeBaseline::class)->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('steel_grades');
    }
};
