<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $fields = json_decode(file_get_contents(resource_path('schemas/installation-cost-properties-v1.json')), true, flags: JSON_THROW_ON_ERROR)['fields'];
        Schema::create('installation_cost_fields', function (Blueprint $table) {
            $table->string('field_key', 64)->primary();
        });
        DB::table('installation_cost_fields')->insert(array_map(fn ($key) => ['field_key' => $key], array_keys($fields)));
        Schema::create('installation_cost_parameters', function (Blueprint $table) use ($fields) {
            $table->foreignUuid('installation_id')->primary()->constrained('installations')->cascadeOnDelete();
            foreach ($fields as $key => $field) {
                $field['type'] === 'number' ? $table->double($key)->nullable() : $table->string($key, 10)->nullable();
            }
        });
        foreach ($fields as $key => $field) {
            $rule = $field['type'] === 'number' ? $key.' BETWEEN '.$field['min'].' AND '.$field['max']
                : $key.' IN ('.implode(',', array_map(fn ($value) => DB::getPdo()->quote($value), $field['options'])).')';
            DB::statement('ALTER TABLE installation_cost_parameters ADD CONSTRAINT installation_cost_'.$key.' CHECK ('.$rule.')');
        }
        Schema::create('installation_cost_annotations', function (Blueprint $table) {
            $table->foreignUuid('installation_id')->constrained('installations')->cascadeOnDelete();
            $table->string('field_key', 64);
            $table->primary(['installation_id', 'field_key']);
            $table->foreign('field_key')->references('field_key')->on('installation_cost_fields');
            $table->boolean('value_present');
            $table->boolean('source_present');
            $table->text('citation')->nullable();
            $table->text('conditions')->nullable();
            $table->unsignedBigInteger('source_by')->nullable();
            $table->timestampTz('source_at', 6)->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at', 6)->nullable();
        });
        DB::statement('ALTER TABLE installation_cost_annotations ADD CONSTRAINT installation_cost_source_pair CHECK ((source_present AND source_by IS NOT NULL AND source_at IS NOT NULL) OR (NOT source_present AND source_by IS NULL AND source_at IS NULL AND citation IS NULL AND conditions IS NULL))');
        DB::statement('ALTER TABLE installation_cost_annotations ADD CONSTRAINT installation_cost_verified_pair CHECK ((verified_by IS NULL)=(verified_at IS NULL))');
        DB::statement('ALTER TABLE installation_cost_annotations ADD CONSTRAINT installation_cost_verified_presence CHECK (verified_at IS NULL OR value_present)');
        DB::statement('ALTER TABLE installation_cost_annotations ADD CONSTRAINT installation_cost_annotation_owner FOREIGN KEY (installation_id) REFERENCES installation_cost_parameters(installation_id) DEFERRABLE INITIALLY DEFERRED');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION guard_installation_cost_shape() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE owner_id uuid; field_filter text; field_name text; a record; p jsonb;
            BEGIN
                owner_id := CASE WHEN TG_OP='DELETE' THEN OLD.installation_id ELSE NEW.installation_id END;
                IF NOT EXISTS (SELECT 1 FROM installations WHERE id=owner_id) THEN RETURN NULL; END IF;
                SELECT to_jsonb(v) INTO p FROM installation_cost_parameters v WHERE installation_id=owner_id;
                IF TG_TABLE_NAME='installation_cost_annotations' THEN
                    field_filter := CASE WHEN TG_OP='DELETE' THEN OLD.field_key ELSE NEW.field_key END;
                END IF;
                FOR field_name IN SELECT field_key FROM installation_cost_fields WHERE field_filter IS NULL OR field_key=field_filter LOOP
                    SELECT * INTO a FROM installation_cost_annotations WHERE installation_id=owner_id AND field_key=field_name;
                    IF p->field_name IS NOT NULL AND p->field_name <> 'null'::jsonb AND (a.field_key IS NULL OR NOT a.value_present) THEN
                        RAISE EXCEPTION 'Cost value requires a presence annotation: %', field_name USING ERRCODE='23514';
                    END IF;
                    IF a.verified_at IS NOT NULL AND (p->field_name IS NULL OR p->field_name='null'::jsonb) THEN
                        RAISE EXCEPTION 'Unknown installation cost cannot be verified: %', field_name USING ERRCODE='23514';
                    END IF;
                END LOOP;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER installation_cost_parameters_shape AFTER INSERT OR UPDATE OR DELETE ON installation_cost_parameters
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_installation_cost_shape();
            CREATE CONSTRAINT TRIGGER installation_cost_annotations_shape AFTER INSERT OR UPDATE OR DELETE ON installation_cost_annotations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_installation_cost_shape();
            SQL);
        foreach (DB::table('installations')->orderBy('id')->cursor() as $record) {
            $values = json_decode($record->cost_values ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $sources = json_decode($record->cost_sources ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $checks = json_decode($record->cost_checks ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $keys = array_unique([...array_keys($values), ...array_keys($sources), ...array_keys($checks)]);
            if (array_diff($keys, array_keys($fields))) throw new RuntimeException('Unknown installation cost prevents normalization: '.$record->id);
            if ($keys === []) continue;
            DB::table('installation_cost_parameters')->insert(['installation_id' => $record->id] + array_replace(array_fill_keys(array_keys($fields), null), $values));
            foreach ($keys as $key) {
                $source = $sources[$key] ?? null;
                $check = $checks[$key] ?? null;
                DB::table('installation_cost_annotations')->insert(['installation_id' => $record->id, 'field_key' => $key, 'value_present' => array_key_exists($key, $values),
                    'source_present' => $source !== null, 'citation' => $source['citation'] ?? null, 'conditions' => $source['conditions'] ?? null,
                    'source_by' => $source['by'] ?? null, 'source_at' => $source['at'] ?? null, 'verified_by' => $check['by'] ?? null, 'verified_at' => $check['at'] ?? null]);
            }
        }
        DB::unprepared(file_get_contents(resource_path('schemas/installation-cost-cards-v1.sql')));
        foreach (['values', 'sources', 'checks'] as $key) {
            if (DB::table('installations as i')->join('installation_cost_cards as c', 'c.installation_id', '=', 'i.id')->whereRaw("coalesce(i.cost_".$key.",'{}'::jsonb) IS DISTINCT FROM c.".$key)->exists()) throw new RuntimeException('Installation cost '.$key.' changed during normalization.');
        }
        Schema::table('installations', fn (Blueprint $table) => $table->dropColumn(['cost_values', 'cost_sources', 'cost_checks']));

    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER installation_cost_parameters_shape ON installation_cost_parameters');
        DB::statement('DROP TRIGGER installation_cost_annotations_shape ON installation_cost_annotations');
        DB::statement('DROP FUNCTION guard_installation_cost_shape()');
        Schema::table('installations', function (Blueprint $table) {
            foreach (['cost_values', 'cost_sources', 'cost_checks'] as $key) $table->jsonb($key)->default('{}');
        });
        DB::statement('UPDATE installations i SET cost_values=c.values,cost_sources=c.sources,cost_checks=c.checks FROM installation_cost_cards c WHERE c.installation_id=i.id');
        DB::statement('DROP VIEW installation_cost_cards');
        Schema::drop('installation_cost_annotations');
        Schema::drop('installation_cost_parameters');
        Schema::drop('installation_cost_fields');
    }
};
