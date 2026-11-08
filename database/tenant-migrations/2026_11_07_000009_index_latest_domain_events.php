<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_events', function (Blueprint $table) {
            $table->index(['entity_type', 'entity_id', 'id'], 'domain_events_entity_latest_idx');
            $table->dropIndex(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::table('domain_events', function (Blueprint $table) {
            $table->index(['entity_type', 'entity_id']);
            $table->dropIndex('domain_events_entity_latest_idx');
        });
    }
};
