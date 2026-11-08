<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('kind', 20);
            $table->string('name');
            $table->string('code', 80)->nullable()->unique();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz();
            $table->index(['parent_id', 'kind']);
        });
        Schema::table('facility_nodes', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('facility_nodes')->restrictOnDelete();
        });
        Schema::create('installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('parent_id')->constrained('facility_nodes')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 80)->nullable()->unique();
            $table->jsonb('passport')->default('{}');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz();
            $table->index('parent_id');
        });
        Schema::create('domain_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity_type', 40);
            $table->uuid('entity_id');
            $table->string('action', 40);
            $table->unsignedBigInteger('actor_id');
            $table->jsonb('before')->nullable();
            $table->jsonb('after');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
        Schema::dropIfExists('installations');
        Schema::dropIfExists('facility_nodes');
    }
};
