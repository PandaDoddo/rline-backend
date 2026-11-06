<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_events', fn (Blueprint $table) => $table->foreignId('target_tenant_id')->nullable()->constrained('tenants')->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('auth_events', fn (Blueprint $table) => $table->dropConstrainedForeignId('target_tenant_id'));
    }
};
