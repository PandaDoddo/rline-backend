<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('installation_id')->constrained('installations')->restrictOnDelete();
            $table->string('profile_code', 50);
            $table->jsonb('passport');
            $table->jsonb('checks')->default('{}');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz();
            $table->index(['installation_id', 'status']);
            $table->index('profile_code');
        });
        DB::statement("CREATE UNIQUE INDEX equipment_installation_tag_unique ON equipment (installation_id, (passport->>'tag')) WHERE passport->>'tag' IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
