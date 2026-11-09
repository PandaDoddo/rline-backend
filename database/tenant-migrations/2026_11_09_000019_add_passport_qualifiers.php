<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', fn (Blueprint $table) => $table->jsonb('passport_qualifiers')->default('{}'));
    }

    public function down(): void
    {
        Schema::table('equipment', fn (Blueprint $table) => $table->dropColumn('passport_qualifiers'));
    }
};
