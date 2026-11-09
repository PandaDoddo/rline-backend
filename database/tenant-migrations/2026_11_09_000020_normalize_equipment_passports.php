<?php

use App\Modules\Diagnostics\Infrastructure\DiagnosticPlanProjection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Released migration schema stays independent of later runtime passport fields. */
    private function fields(): array
    {
        return json_decode(file_get_contents(resource_path('schema/equipment-passport-v2.json')), true, flags: JSON_THROW_ON_ERROR)['fields'];
    }

    private function tables(): array
    {
        $tables = [];
        foreach ($this->fields() as $key => $field) {
            $tables[$field['table']][$key] = $field;
        }

        return $tables;
    }

    public function up(): void
    {
        DB::statement('LOCK TABLE equipment IN ACCESS EXCLUSIVE MODE');
        foreach ($this->tables() as $table => $fields) {
            $columns = function (Blueprint $blueprint) use ($fields, $table) {
                if ($table !== 'equipment') {
                    $blueprint->foreignUuid('equipment_id')->primary()->constrained('equipment')->cascadeOnDelete();
                }
                foreach ($fields as $key => $field) {
                    match ($field['type']) {
                        'integer' => $blueprint->integer($key)->nullable(),
                        'double' => $blueprint->double($key)->nullable(),
                        'date' => $blueprint->date($key)->nullable(),
                        default => $blueprint->string($key, (int) ($field['max'] ?? 2000))->nullable(),
                    };
                }
            };
            $table === 'equipment' ? Schema::table($table, $columns) : Schema::create($table, $columns);
            foreach ($fields as $key => $field) {
                $checks = [];
                if ($field['type'] === 'integer' || $field['type'] === 'double') {
                    foreach (['min' => '>=', 'max' => '<='] as $bound => $operator) {
                        if (isset($field[$bound])) {
                            $checks[] = '"'.$key.'" '.$operator.' '.$field[$bound];
                        }
                    }
                    if ($field['type'] === 'double') {
                        $checks[] = '"'.$key.'" NOT IN (\'NaN\'::float8,\'Infinity\'::float8,\'-Infinity\'::float8)';
                    }
                }
                if ($field['options']) {
                    $options = array_map(fn ($option) => DB::getPdo()->quote($option), $field['options']);
                    $checks[] = '"'.$key.'" IN ('.implode(',', $options).')';
                }
                if ($checks) {
                    DB::statement('ALTER TABLE "'.$table.'" ADD CHECK ('.implode(' AND ', $checks).')');
                }
            }
        }
        Schema::create('equipment_passport_fields', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->string('owner_table', 80);
            $table->string('data_type', 12);
        });
        DB::table('equipment_passport_fields')->insert(collect($this->fields())->map(fn ($field, $key) => ['key' => $key, 'owner_table' => $field['table'], 'data_type' => $field['type']])->values()->all());
        Schema::create('equipment_passport_annotations', function (Blueprint $table) {
            $table->foreignUuid('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('field_key', 100);
            $table->foreign('field_key')->references('key')->on('equipment_passport_fields')->restrictOnDelete();
            $table->string('state', 20)->nullable();
            $table->string('precision', 8)->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->primary(['equipment_id', 'field_key']);
            $table->index(['field_key', 'state']);
        });
        DB::statement("ALTER TABLE equipment_passport_annotations ADD CHECK (state IN ('unknown','not_applicable','not_present'))");
        DB::statement("ALTER TABLE equipment_passport_annotations ADD CHECK (precision IN ('day','year'))");
        DB::statement('ALTER TABLE equipment_passport_annotations ADD CHECK ((verified_at IS NULL) = (verified_by IS NULL))');
        DB::table('equipment')->orderBy('id')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                $passport = json_decode($row->passport, true, flags: JSON_THROW_ON_ERROR);
                if (array_diff(array_keys($passport), array_keys($this->fields()))) {
                    throw new RuntimeException('Unmapped passport fields: '.$row->id);
                }
                foreach ($this->tables() as $table => $fields) {
                    $values = [];
                    foreach ($fields as $key => $field) {
                        $values[$key] = $passport[$key] ?? null;
                    }
                    if ($table === 'equipment') {
                        DB::table($table)->where('id', $row->id)->update($values);
                    } else {
                        DB::table($table)->insert(['equipment_id' => $row->id] + $values);
                    }
                }
                $qualifiers = json_decode($row->passport_qualifiers, true, flags: JSON_THROW_ON_ERROR);
                $checks = json_decode($row->checks, true, flags: JSON_THROW_ON_ERROR);
                foreach (array_unique([...array_keys($qualifiers), ...array_keys($checks)]) as $key) {
                    $qualifier = $qualifiers[$key] ?? [];
                    $check = $checks[$key] ?? [];
                    DB::table('equipment_passport_annotations')->insert(['equipment_id' => $row->id, 'field_key' => $key,
                        'state' => $qualifier['state'] ?? null, 'precision' => $qualifier['precision'] ?? null, 'verified_by' => $check['by'] ?? null, 'verified_at' => $check['at'] ?? null]);
                }
            }
        });
        $parts = [];
        $joins = [];
        foreach ($this->tables() as $table => $fields) {
            if ($table === 'equipment') {
                $pairs = [];
                foreach ($fields as $key => $field) {
                    $pairs[] = "'".$key."',e.\"".$key.'"';
                }
                $parts[] = 'jsonb_build_object('.implode(',', $pairs).')';
            } else {
                $alias = 'p'.count($joins);
                $joins[] = 'LEFT JOIN "'.$table.'" '.$alias.' ON '.$alias.'.equipment_id=e.id';
                $parts[] = "COALESCE(to_jsonb($alias)-'equipment_id','{}'::jsonb)";
            }
        }
        DB::statement('CREATE VIEW equipment_passports AS SELECT e.id equipment_id,jsonb_strip_nulls('.implode(' || ', $parts).") passport,COALESCE(a.qualifiers,'{}'::jsonb) qualifiers,COALESCE(a.checks,'{}'::jsonb) checks FROM equipment e ".implode(' ', $joins).<<<'SQL'
 LEFT JOIN LATERAL (
 SELECT jsonb_object_agg(field_key,jsonb_strip_nulls(jsonb_build_object('state',state,'precision',precision))) FILTER (WHERE state IS NOT NULL OR precision IS NOT NULL) qualifiers,
 jsonb_object_agg(field_key,jsonb_build_object('by',verified_by,'at',verified_at)) FILTER (WHERE verified_at IS NOT NULL) checks
 FROM equipment_passport_annotations WHERE equipment_id=e.id
 ) a ON TRUE
SQL);
        if (DB::table('equipment as e')->join('equipment_passports as p', 'p.equipment_id', '=', 'e.id')->whereRaw('jsonb_strip_nulls(e.passport) IS DISTINCT FROM p.passport')->exists()) {
            throw new RuntimeException('Passport normalization comparison failed; source columns retained by rollback.');
        }
        DB::statement('ALTER TABLE equipment ALTER COLUMN name SET NOT NULL');
        DB::statement('DROP INDEX equipment_installation_tag_unique');
        DB::statement('CREATE UNIQUE INDEX equipment_installation_tag_unique ON equipment (installation_id,tag) WHERE tag IS NOT NULL');
        DB::statement('CREATE INDEX equipment_name_sort_index ON equipment (name,id)');
        DB::statement('CREATE INDEX equipment_commissioned_sort_index ON equipment (commissioned_on,id)');
        DB::statement(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', DiagnosticPlanProjection::definition(config('equipment.profiles'), true)));
        Schema::table('equipment', fn (Blueprint $table) => $table->dropColumn(['passport', 'passport_qualifiers', 'checks']));
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->jsonb('passport')->default('{}');
            $table->jsonb('passport_qualifiers')->default('{}');
            $table->jsonb('checks')->default('{}');
        });
        DB::statement('UPDATE equipment e SET passport=p.passport,passport_qualifiers=p.qualifiers,checks=p.checks FROM equipment_passports p WHERE p.equipment_id=e.id');
        DB::statement('DROP VIEW equipment_passports');
        DB::statement(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', DiagnosticPlanProjection::definition(config('equipment.profiles'))));
        Schema::dropIfExists('equipment_passport_annotations');
        Schema::dropIfExists('equipment_passport_fields');
        DB::statement('DROP INDEX equipment_installation_tag_unique');
        foreach ($this->tables() as $table => $fields) {
            if ($table === 'equipment') {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn(array_keys($fields)));
            } else {
                Schema::dropIfExists($table);
            }
        }
        DB::statement("CREATE UNIQUE INDEX equipment_installation_tag_unique ON equipment (installation_id,(passport->>'tag')) WHERE passport->>'tag' IS NOT NULL");
    }
};
