<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_import_substances', function (Blueprint $table) {
            $table->foreignUuid('substance_id')->primary()->constrained('substances')->restrictOnDelete();
            $table->uuid('batch_id');
            $table->string('source_code', 100);
            $table->unsignedInteger('imported_version');
            $table->unique(['batch_id', 'source_code']);
        });
        DB::statement('ALTER TABLE dataset_import_substances ADD FOREIGN KEY(batch_id) REFERENCES dataset_import_batches(id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE dataset_import_substances ADD CHECK(imported_version>=1)');
        DB::statement('CREATE TRIGGER dataset_import_substance_immutable BEFORE UPDATE OR DELETE ON dataset_import_substances FOR EACH ROW EXECUTE FUNCTION reject_dataset_import_receipt_mutation()');
    }

    public function down(): void
    {
        if (DB::table('dataset_import_substances')->exists()) {
            throw new RuntimeException('Cannot discard substance import evidence.');
        }
        Schema::drop('dataset_import_substances');
    }
};
