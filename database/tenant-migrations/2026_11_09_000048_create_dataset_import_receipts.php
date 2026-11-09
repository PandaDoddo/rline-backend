<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_kind', 30);
            $table->string('source_version', 100);
            $table->string('label');
            $table->timestampTz('source_snapshot_at', 6);
            $table->char('manifest_hash', 64)->unique();
            $table->jsonb('manifest');
            $table->jsonb('summary');
            $table->unsignedBigInteger('applied_by');
            $table->timestampTz('applied_at', 6);
            $table->index(['applied_at', 'id']);
        });
        Schema::create('dataset_import_equipment', function (Blueprint $table) {
            $table->foreignUuid('equipment_id')->primary()->constrained('equipment')->restrictOnDelete();
            $table->foreignUuid('batch_id')->constrained('dataset_import_batches')->restrictOnDelete();
            $table->string('source_id', 100);
            $table->char('input_signature', 64);
            $table->unsignedInteger('imported_version');
            $table->unique(['batch_id', 'source_id']);
        });
        Schema::create('dataset_import_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('dataset_import_batches')->restrictOnDelete();
            $table->foreignUuid('equipment_id')->nullable()->constrained('equipment')->restrictOnDelete();
            $table->string('source_path', 1000);
            $table->string('kind', 40);
            $table->string('severity', 10);
            $table->jsonb('source_value')->nullable();
            $table->text('message');
            $table->index(['batch_id', 'severity', 'id']);
            $table->index(['equipment_id', 'id']);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE dataset_import_batches ADD CHECK (source_kind IN ('stored_snapshot','demo_expanded')), ADD CHECK (manifest_hash ~ '^[0-9a-f]{64}$');
ALTER TABLE dataset_import_equipment ADD CHECK (imported_version>=1), ADD CHECK (input_signature ~ '^[0-9a-f]{64}$');
ALTER TABLE dataset_import_issues ADD CHECK (severity IN ('info','warning','error'));
CREATE FUNCTION reject_dataset_import_receipt_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Import source evidence is immutable; record a correction separately' USING ERRCODE='23514';
END $$;
CREATE TRIGGER dataset_import_batch_immutable BEFORE UPDATE OR DELETE ON dataset_import_batches
 FOR EACH ROW EXECUTE FUNCTION reject_dataset_import_receipt_mutation();
CREATE TRIGGER dataset_import_equipment_immutable BEFORE UPDATE OR DELETE ON dataset_import_equipment
 FOR EACH ROW EXECUTE FUNCTION reject_dataset_import_receipt_mutation();
CREATE TRIGGER dataset_import_issue_immutable BEFORE UPDATE OR DELETE ON dataset_import_issues
 FOR EACH ROW EXECUTE FUNCTION reject_dataset_import_receipt_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::table('dataset_import_batches')->exists()) {
            throw new RuntimeException('Cannot discard applied import evidence.');
        }
        Schema::dropIfExists('dataset_import_issues');
        Schema::dropIfExists('dataset_import_equipment');
        Schema::dropIfExists('dataset_import_batches');
        DB::statement('DROP FUNCTION reject_dataset_import_receipt_mutation()');
    }
};
