<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_maintenance_records', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        DB::statement('ALTER TABLE installation_maintenance_records ADD CONSTRAINT maintenance_record_version CHECK(version>=1)');
        Schema::create('dataset_import_maintenance', function (Blueprint $table) {
            $table->uuid('record_id')->primary();
            $table->uuid('installation_id');
            $table->string('kind', 10);
            $table->uuid('batch_id');
            $table->string('source_reference', 1000);
            $table->boolean('source_verified')->nullable();
            $table->unsignedInteger('imported_version');
            $table->unique(['batch_id', 'installation_id', 'source_reference'], 'maintenance_import_source_unique');
            $table->foreign(['record_id', 'installation_id', 'kind'], 'maintenance_import_record_owner_fk')->references(['id', 'installation_id', 'kind'])->on('installation_maintenance_records')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE dataset_import_maintenance ADD CONSTRAINT maintenance_import_installation_batch_fk FOREIGN KEY(installation_id,batch_id) REFERENCES dataset_import_installations(installation_id,batch_id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE dataset_import_maintenance ADD CHECK(imported_version>=1)');
        DB::statement('CREATE TRIGGER dataset_import_maintenance_immutable BEFORE UPDATE OR DELETE ON dataset_import_maintenance FOR EACH ROW EXECUTE FUNCTION reject_dataset_import_receipt_mutation()');
    }

    public function down(): void
    {
        if (DB::table('dataset_import_maintenance')->exists()) {
            throw new RuntimeException('Cannot discard maintenance import evidence.');
        }
        Schema::drop('dataset_import_maintenance');
        Schema::table('installation_maintenance_records', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
