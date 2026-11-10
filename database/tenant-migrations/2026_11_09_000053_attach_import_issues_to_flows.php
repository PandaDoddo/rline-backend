<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE dataset_import_flows ADD CONSTRAINT dataset_import_flows_batch_unique UNIQUE(flow_id,batch_id)');
        Schema::table('dataset_import_issues', fn (Blueprint $table) => $table->uuid('flow_id')->nullable()->index());
        DB::statement('ALTER TABLE dataset_import_issues ADD CONSTRAINT dataset_import_issue_flow_fk FOREIGN KEY(flow_id,batch_id) REFERENCES dataset_import_flows(flow_id,batch_id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE dataset_import_issues ADD CONSTRAINT dataset_import_issue_single_owner CHECK(num_nonnulls(equipment_id,flow_id)<=1)');
    }

    public function down(): void
    {
        if (DB::table('dataset_import_issues')->whereNotNull('flow_id')->exists()) {
            throw new RuntimeException('Cannot discard flow source issues.');
        }
        DB::statement('ALTER TABLE dataset_import_issues DROP CONSTRAINT dataset_import_issue_single_owner');
        Schema::table('dataset_import_issues', fn (Blueprint $table) => $table->dropColumn('flow_id'));
        DB::statement('ALTER TABLE dataset_import_flows DROP CONSTRAINT dataset_import_flows_batch_unique');
    }
};
