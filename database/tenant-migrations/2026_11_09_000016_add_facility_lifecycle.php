<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_nodes', fn (Blueprint $table) => $table->string('status', 16)->default('active')->index());
        Schema::table('installations', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->index();
            $table->jsonb('archive_manifest')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('facility_nodes', fn (Blueprint $table) => $table->dropColumn('status'));
        Schema::table('installations', fn (Blueprint $table) => $table->dropColumn(['status', 'archive_manifest']));
    }
};
