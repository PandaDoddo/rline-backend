<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_flow_nodes', fn (Blueprint $table) => $table->jsonb('screening')->nullable());
    }

    public function down(): void
    {
        Schema::table('process_flow_nodes', fn (Blueprint $table) => $table->dropColumn('screening'));
    }
};
