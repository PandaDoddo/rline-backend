<?php

use App\Modules\Shared\Domain\ValueEquality;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function fields(): array
    {
        return json_decode(file_get_contents(resource_path('schema/installation-passport-v1.json')), true, flags: JSON_THROW_ON_ERROR)['fields'];
    }

    public function up(): void
    {
        DB::statement('LOCK TABLE installations IN ACCESS EXCLUSIVE MODE');
        $fields = $this->fields();
        Schema::create('installation_passports', function (Blueprint $table) use ($fields) {
            $table->foreignUuid('installation_id')->primary()->constrained('installations')->cascadeOnDelete();
            foreach ($fields as $field) {
                match ($field['type']) {
                    'number' => $table->double($field['column'])->nullable(),
                    'integer' => $table->integer($field['column'])->nullable(),
                    default => $table->string($field['column'], $field['max'])->nullable(),
                };
            }
        });
        foreach ($fields as $field) {
            $column = '"'.$field['column'].'"';
            $checks = [];
            if ($field['type'] !== 'text') {
                $checks[] = $column.' BETWEEN 0 AND '.$field['max'];
                if ($field['type'] === 'number') {
                    $checks[] = $column." NOT IN ('NaN'::float8,'Infinity'::float8,'-Infinity'::float8)";
                }
            }
            if ($field['options']) {
                $checks[] = $column.' IN ('.implode(',', array_map(fn ($value) => DB::getPdo()->quote($value), $field['options'])).')';
            }
            if ($checks) {
                DB::statement('ALTER TABLE installation_passports ADD CHECK ('.implode(' AND ', $checks).')');
            }
        }
        DB::statement('ALTER TABLE installation_passports ADD CHECK ((planned_capacity IS NULL AND actual_capacity IS NULL) OR (capacity_unit IS NOT NULL AND length(btrim(capacity_unit))>0))');
        DB::table('installations')->select(['id', 'passport'])->orderBy('id')->chunk(100, function ($rows) use ($fields) {
            foreach ($rows as $row) {
                $input = json_decode($row->passport, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($input) || array_diff(array_keys($input), array_keys($fields))) {
                    throw new RuntimeException('Unmapped installation passport: '.$row->id);
                }
                $columns = [];
                foreach ($fields as $key => $field) {
                    $columns[$field['column']] = $input[$key] ?? null;
                }
                $meaningful = array_filter($columns, fn ($value) => $value !== null);
                if ($meaningful) {
                    DB::table('installation_passports')->insert(['installation_id' => $row->id] + $columns);
                }
                $saved = (array) DB::table('installation_passports')->where('installation_id', $row->id)->first();
                $output = [];
                foreach ($fields as $key => $field) {
                    $value = $saved[$field['column']] ?? null;
                    if ($value !== null) {
                        $output[$key] = match ($field['type']) {
                            'number' => (float) $value, 'integer' => (int) $value, default => $value
                        };
                    }
                }
                if (! ValueEquality::same(array_filter($input, fn ($value) => $value !== null), $output)) {
                    throw new RuntimeException('Installation passport migration comparison failed: '.$row->id);
                }
            }
        });
        Schema::table('installations', fn (Blueprint $table) => $table->dropColumn('passport'));
    }

    public function down(): void
    {
        Schema::table('installations', fn (Blueprint $table) => $table->jsonb('passport')->default('{}'));
        $pairs = [];
        foreach ($this->fields() as $key => $field) {
            $pairs[] = DB::getPdo()->quote($key).',p."'.$field['column'].'"';
        }
        DB::statement('UPDATE installations i SET passport=jsonb_strip_nulls(jsonb_build_object('.implode(',', $pairs).')) FROM installation_passports p WHERE p.installation_id=i.id');
        Schema::dropIfExists('installation_passports');
    }
};
