<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installations', function (Blueprint $table) {
            foreach (['cost_values', 'cost_sources', 'cost_checks'] as $column) {
                $table->jsonb($column)->default('{}');
            }
        });
    }

    public function down(): void
    {
        Schema::table('installations', fn (Blueprint $table) => $table->dropColumn(['cost_values', 'cost_sources', 'cost_checks']));
    }
};
