<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_import_installations', function (Blueprint $table) {
            $table->foreignUuid('installation_id')->primary()->constrained('installations')->restrictOnDelete();
            $table->uuid('batch_id');
            $table->string('source_id', 100);
            $table->unsignedInteger('imported_version');
            $table->unique(['batch_id', 'source_id']);
            $table->unique(['installation_id', 'batch_id']);
        });
        DB::statement('ALTER TABLE dataset_import_installations ADD FOREIGN KEY(batch_id) REFERENCES dataset_import_batches(id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE dataset_import_installations ADD CHECK(imported_version>=1)');
        DB::statement('CREATE TRIGGER dataset_import_installation_immutable BEFORE UPDATE OR DELETE ON dataset_import_installations FOR EACH ROW EXECUTE FUNCTION reject_dataset_import_receipt_mutation()');
        Schema::table('dataset_import_issues', fn (Blueprint $table) => $table->uuid('installation_id')->nullable()->index());
        DB::statement('ALTER TABLE dataset_import_issues ADD CONSTRAINT dataset_import_issue_installation_fk FOREIGN KEY(installation_id,batch_id) REFERENCES dataset_import_installations(installation_id,batch_id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE dataset_import_issues DROP CONSTRAINT dataset_import_issue_single_owner');
        DB::statement('ALTER TABLE dataset_import_issues ADD CONSTRAINT dataset_import_issue_single_owner CHECK(num_nonnulls(equipment_id,flow_id,installation_id)<=1)');
    }

    public function down(): void
    {
        if (DB::table('dataset_import_installations')->exists() || DB::table('dataset_import_issues')->whereNotNull('installation_id')->exists()) {
            throw new RuntimeException('Cannot discard installation import evidence.');
        }
        DB::statement('ALTER TABLE dataset_import_issues DROP CONSTRAINT dataset_import_issue_single_owner');
        Schema::table('dataset_import_issues', fn (Blueprint $table) => $table->dropColumn('installation_id'));
        DB::statement('ALTER TABLE dataset_import_issues ADD CONSTRAINT dataset_import_issue_single_owner CHECK(num_nonnulls(equipment_id,flow_id)<=1)');
        Schema::drop('dataset_import_installations');
    }
};
