<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_flow_nodes', fn (Blueprint $table) => $table->string('code', 80)->nullable());
        DB::statement('CREATE UNIQUE INDEX flow_installation_code_unique ON process_flow_nodes(installation_id,code) WHERE code IS NOT NULL');
        DB::statement('ALTER TABLE process_flow_nodes ADD CHECK(code IS NULL OR (code=btrim(code) AND length(code)>0))');
        Schema::create('dataset_import_flows', function (Blueprint $table) {
            $table->foreignUuid('flow_id')->primary()->constrained('process_flow_nodes')->restrictOnDelete();
            $table->uuid('batch_id');
            $table->string('source_id', 200);
            $table->unsignedInteger('imported_version');
            $table->unique(['batch_id', 'source_id']);
        });
        DB::statement('ALTER TABLE dataset_import_flows ADD FOREIGN KEY(batch_id) REFERENCES dataset_import_batches(id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE dataset_import_flows ADD CHECK(imported_version>=1)');
        DB::statement('CREATE TRIGGER dataset_import_flow_immutable BEFORE UPDATE OR DELETE ON dataset_import_flows FOR EACH ROW EXECUTE FUNCTION reject_dataset_import_receipt_mutation()');
    }

    public function down(): void
    {
        if (DB::table('dataset_import_flows')->exists() || DB::table('process_flow_nodes')->whereNotNull('code')->exists()) {
            throw new RuntimeException('Cannot discard flow identifiers or source evidence.');
        }
        Schema::drop('dataset_import_flows');
        Schema::table('process_flow_nodes', fn (Blueprint $table) => $table->dropColumn('code'));
    }
};
