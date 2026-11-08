<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installations', fn (Blueprint $table) => $table->boolean('no_stops')->nullable());
        Schema::create('installation_maintenance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('installation_id')->constrained('installations')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->jsonb('values')->default('{}');
            $table->jsonb('check')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['installation_id', 'kind', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_maintenance_records');
        Schema::table('installations', fn (Blueprint $table) => $table->dropColumn('no_stops'));
    }
};
