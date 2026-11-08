<?php

use App\Domain\DamageMechanismBaseline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damage_mechanisms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legacy_code', 100)->unique();
            $table->string('label', 255);
            $table->string('origin', 20)->default('base');
            $table->string('base_version', 100);
            foreach (['base_values', 'base_raw', 'overrides', 'sources', 'checks'] as $column) {
                $table->jsonb($column)->default('{}');
            }
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX damage_mechanisms_active_name_unique ON damage_mechanisms (lower(label)) WHERE status = 'active'");
        app(DamageMechanismBaseline::class)->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_mechanisms');
    }
};
