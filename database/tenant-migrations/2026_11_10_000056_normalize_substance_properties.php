<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $fields = json_decode(file_get_contents(resource_path('schemas/substance-properties-v1.json')), true, flags: JSON_THROW_ON_ERROR)['fields'];
        Schema::create('substance_property_fields', function (Blueprint $table) {
            $table->string('field_key', 64)->primary();
            $table->string('column_name', 64)->nullable()->unique();
            $table->string('value_type', 20);
        });
        Schema::create('substance_property_qualifiers', function (Blueprint $table) {
            $table->string('field_key', 64);
            $table->string('qualifier', 100);
            $table->primary(['field_key', 'qualifier']);
            $table->foreign('field_key')->references('field_key')->on('substance_property_fields');
        });
        foreach ($fields as $key => $field) {
            DB::table('substance_property_fields')->insert(['field_key' => $key, 'column_name' => $field['column'], 'value_type' => $field['type']]);
            foreach ($field['qualifiers'] ?? [] as $qualifier) {
                DB::table('substance_property_qualifiers')->insert(['field_key' => $key, 'qualifier' => $qualifier]);
            }
        }
        Schema::create('substance_properties', function (Blueprint $table) use ($fields) {
            $table->foreignUuid('substance_id')->primary()->constrained('substances')->cascadeOnDelete();
            foreach ($fields as $field) {
                if ($field['column'] === null) {
                    continue;
                }
                $method = match ($field['type']) {
                    'number' => 'double', 'integer' => 'integer', 'boolean' => 'boolean', default => 'text'
                };
                $table->{$method}($field['column'])->nullable();
            }
        });
        foreach ($fields as $key => $field) {
            if ($field['column'] === null) {
                continue;
            }
            $column = '"'.$field['column'].'"';
            if (in_array($field['type'], ['number', 'integer'], true)) {
                $rule = $column.' BETWEEN '.$field['min'].' AND '.$field['max'];
            } elseif ($field['type'] === 'choice') {
                $rule = $column.' IN ('.implode(',', array_map(fn ($value) => DB::getPdo()->quote($value), $field['options'])).')';
            } elseif ($field['type'] === 'text') {
                $rule = 'length('.$column.') <= '.$field['max'];
            } else {
                continue;
            }
            DB::statement('ALTER TABLE substance_properties ADD CONSTRAINT substance_value_'.$field['column'].' CHECK ('.$rule.')');
        }
        Schema::create('substance_property_annotations', function (Blueprint $table) {
            $table->foreignUuid('substance_id')->constrained('substances')->cascadeOnDelete();
            $table->string('field_key', 64);
            $table->primary(['substance_id', 'field_key']);
            $table->foreign('field_key')->references('field_key')->on('substance_property_fields');
            $table->boolean('value_present');
            $table->string('qualifier', 100)->nullable();
            $table->foreign(['field_key', 'qualifier'])->references(['field_key', 'qualifier'])->on('substance_property_qualifiers');
            $table->boolean('source_present');
            $table->text('citation')->nullable();
            $table->text('conditions')->nullable();
            $table->unsignedBigInteger('source_by')->nullable();
            $table->timestampTz('source_at', 6)->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at', 6)->nullable();
        });
        DB::statement('ALTER TABLE substance_property_annotations ADD CONSTRAINT substance_source_pair CHECK ((source_present AND source_by IS NOT NULL AND source_at IS NOT NULL) OR (NOT source_present AND source_by IS NULL AND source_at IS NULL AND citation IS NULL AND conditions IS NULL))');
        DB::statement('ALTER TABLE substance_property_annotations ADD CONSTRAINT substance_verified_pair CHECK ((verified_by IS NULL)=(verified_at IS NULL))');
        DB::statement('ALTER TABLE substance_property_annotations ADD CONSTRAINT substance_qualifier_presence CHECK (qualifier IS NULL OR value_present)');
        DB::statement('ALTER TABLE substance_property_annotations ADD CONSTRAINT substance_annotation_property_owner FOREIGN KEY (substance_id) REFERENCES substance_properties(substance_id) DEFERRABLE INITIALLY DEFERRED');
        foreach (DB::table('substances')->orderBy('id')->cursor() as $record) {
            $values = json_decode($record->overrides, true, flags: JSON_THROW_ON_ERROR);
            $sources = json_decode($record->sources, true, flags: JSON_THROW_ON_ERROR);
            $checks = json_decode($record->checks, true, flags: JSON_THROW_ON_ERROR);
            $keys = array_unique([...array_keys($values), ...array_keys($sources), ...array_keys($checks)]);
            if (array_diff($keys, array_keys($fields))) {
                throw new RuntimeException('Unknown substance properties prevent normalization: '.$record->id);
            }
            if ($keys === []) {
                continue;
            }
            $properties = ['substance_id' => $record->id];
            foreach ($fields as $key => $field) {
                if ($field['column'] === null) {
                    if (array_key_exists($key, $values)) {
                        throw new RuntimeException('Read-only property override prevents normalization: '.$key);
                    }

                    continue;
                }
                $value = $values[$key] ?? null;
                $properties[$field['column']] = in_array($value, $field['qualifiers'] ?? [], true) ? null : $value;
            }
            DB::table('substance_properties')->insert($properties);
            foreach ($keys as $key) {
                $value = $values[$key] ?? null;
                $source = $sources[$key] ?? null;
                $check = $checks[$key] ?? null;
                DB::table('substance_property_annotations')->insert(['substance_id' => $record->id, 'field_key' => $key,
                    'value_present' => array_key_exists($key, $values), 'qualifier' => in_array($value, $fields[$key]['qualifiers'] ?? [], true) ? $value : null,
                    'source_present' => $source !== null, 'citation' => $source['citation'] ?? null, 'conditions' => $source['conditions'] ?? null,
                    'source_by' => $source['by'] ?? null, 'source_at' => $source['at'] ?? null, 'verified_by' => $check['by'] ?? null, 'verified_at' => $check['at'] ?? null]);
            }
        }
        DB::unprepared(file_get_contents(resource_path('schemas/substance-cards-v1.sql')));
        foreach (['overrides', 'sources', 'checks'] as $key) {
            if (DB::table('substances as s')->join('substance_cards as c', 'c.substance_id', '=', 's.id')->whereRaw('s.'.$key.' IS DISTINCT FROM c.'.$key)->exists()) {
                throw new RuntimeException('Substance '.$key.' changed during normalization.');
            }
        }
        Schema::table('substances', fn (Blueprint $table) => $table->dropColumn(['overrides', 'sources', 'checks']));
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION enforce_substance_property_shape() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE owner_id uuid; field_filter text; f record; a record; p jsonb; effective jsonb; baseline jsonb;
            BEGIN
                owner_id := CASE WHEN TG_OP='DELETE' THEN OLD.substance_id ELSE NEW.substance_id END;
                SELECT base_values INTO baseline FROM substances WHERE id=owner_id;
                IF NOT FOUND THEN RETURN NULL; END IF;
                SELECT to_jsonb(v) INTO p FROM substance_properties v WHERE substance_id=owner_id;
                IF TG_TABLE_NAME='substance_property_annotations' THEN
                    field_filter := CASE WHEN TG_OP='DELETE' THEN OLD.field_key ELSE NEW.field_key END;
                END IF;
                FOR f IN SELECT * FROM substance_property_fields
                    WHERE field_filter IS NULL OR field_key=field_filter
                LOOP
                    SELECT * INTO a FROM substance_property_annotations WHERE substance_id=owner_id AND field_key=f.field_key;
                    IF p->f.column_name IS NOT NULL AND p->f.column_name <> 'null'::jsonb
                        AND (a.field_key IS NULL OR NOT a.value_present OR a.qualifier IS NOT NULL) THEN
                        RAISE EXCEPTION 'Substance property conflicts with its presence or qualifier: %', f.field_key USING ERRCODE='23514';
                    END IF;
                    IF a.field_key IS NOT NULL THEN
                        IF a.value_present AND f.column_name IS NULL THEN
                            RAISE EXCEPTION 'Read-only baseline property cannot be overridden: %', f.field_key USING ERRCODE='23514';
                        END IF;
                        effective := CASE WHEN a.value_present THEN coalesce(to_jsonb(a.qualifier),p->f.column_name,'null'::jsonb) ELSE baseline->f.field_key END;
                        IF a.verified_at IS NOT NULL AND (effective IS NULL OR effective IN ('null'::jsonb,'""'::jsonb,'[]'::jsonb)) THEN
                            RAISE EXCEPTION 'Unknown substance property cannot be verified: %', f.field_key USING ERRCODE='23514';
                        END IF;
                    END IF;
                END LOOP;
                RETURN NULL;
            END;
            $$;
            CREATE CONSTRAINT TRIGGER substance_properties_shape AFTER INSERT OR UPDATE OR DELETE ON substance_properties
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION enforce_substance_property_shape();
            CREATE CONSTRAINT TRIGGER substance_annotations_shape AFTER INSERT OR UPDATE OR DELETE ON substance_property_annotations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION enforce_substance_property_shape();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER substance_properties_shape ON substance_properties');
        DB::statement('DROP TRIGGER substance_annotations_shape ON substance_property_annotations');
        DB::statement('DROP FUNCTION enforce_substance_property_shape()');
        Schema::table('substances', function (Blueprint $table) {
            foreach (['overrides', 'sources', 'checks'] as $key) {
                $table->jsonb($key)->default('{}');
            }
        });
        DB::statement('UPDATE substances s SET overrides=c.overrides,sources=c.sources,checks=c.checks FROM substance_cards c WHERE c.substance_id=s.id');
        DB::statement('DROP VIEW substance_cards');
        Schema::drop('substance_property_annotations');
        Schema::drop('substance_properties');
        Schema::drop('substance_property_qualifiers');
        Schema::drop('substance_property_fields');
    }
};
