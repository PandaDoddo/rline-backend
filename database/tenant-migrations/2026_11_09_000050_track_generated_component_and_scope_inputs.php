<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE equipment_operating_scopes ADD CONSTRAINT operating_scopes_id_owner_unique UNIQUE(id,equipment_id)');
        foreach (['component', 'scope', 'cml'] as $owner) {
            Schema::create('dataset_import_'.$owner.'_origins', function (Blueprint $table) use ($owner) {
                $table->uuid($owner.'_id');
                $table->uuid('equipment_id');
                $table->uuid('batch_id');
                $table->string('field_key', 64);
                $table->jsonb('imported_value');
                $table->primary([$owner.'_id', 'field_key']);
                $table->index('equipment_id');
            });
            $target = match ($owner) {
                'component' => 'equipment_components', 'scope' => 'equipment_operating_scopes', 'cml' => 'equipment_cmls'
            };
            DB::statement('ALTER TABLE dataset_import_'.$owner.'_origins ADD FOREIGN KEY('.$owner.'_id,equipment_id) REFERENCES '.$target.'(id,equipment_id)');
            DB::statement('ALTER TABLE dataset_import_'.$owner.'_origins ADD FOREIGN KEY(equipment_id,batch_id) REFERENCES dataset_import_equipment(equipment_id,batch_id) DEFERRABLE INITIALLY DEFERRED');
            DB::statement("ALTER TABLE dataset_import_{$owner}_origins ADD CHECK (jsonb_typeof(imported_value) IN ('number','string','boolean','null'))");
            DB::statement('CREATE TRIGGER dataset_import_'.$owner.'_origin_immutable BEFORE UPDATE OR DELETE ON dataset_import_'.$owner.'_origins FOR EACH ROW EXECUTE FUNCTION reject_dataset_import_receipt_mutation()');
        }
        DB::statement("ALTER TABLE dataset_import_component_origins ADD CHECK(field_key IN ('nominal_thickness_mm','rejection_passport_mm','diameter_mm','length_mm'))");
        DB::statement("ALTER TABLE dataset_import_cml_origins ADD CHECK(field_key='point_count')");
        DB::statement('ALTER TABLE dataset_import_scope_origins ADD FOREIGN KEY(field_key) REFERENCES equipment_operating_scope_fields(field_key)');
    }

    public function down(): void
    {
        foreach (['component', 'scope', 'cml'] as $owner) {
            if (DB::table('dataset_import_'.$owner.'_origins')->exists()) {
                throw new RuntimeException('Cannot discard generated input evidence.');
            }
        }
        foreach (['component', 'scope', 'cml'] as $owner) {
            Schema::drop('dataset_import_'.$owner.'_origins');
        }
        DB::statement('ALTER TABLE equipment_operating_scopes DROP CONSTRAINT operating_scopes_id_owner_unique');
    }
};
