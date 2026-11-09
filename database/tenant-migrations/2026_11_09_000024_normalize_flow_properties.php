<?php

use App\Modules\ProcessFlows\Infrastructure\FlowCardProjection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIELDS = ['temperature' => 'double', 'pressure' => 'double', 'ph' => 'double', 'density' => 'double', 'molecular_weight' => 'double', 'boiling_point' => 'double', 'phase' => 'text', 'rep_environment' => 'text', 'soil_type' => 'text', 'insulation_type' => 'text', 'insulation_chlorides' => 'text', 'notes' => 'text'];

    public function up(): void
    {
        DB::statement('LOCK TABLE process_flow_nodes,process_flow_calculations IN ACCESS EXCLUSIVE MODE');
        Schema::create('process_flow_property_fields', function (Blueprint $table) {
            $table->string('field_key', 64)->primary();
            $table->string('value_type', 16);
        });
        foreach (self::FIELDS as $key => $type) {
            DB::table('process_flow_property_fields')->insert(['field_key' => $key, 'value_type' => $type]);
        }
        Schema::create('process_flow_properties', function (Blueprint $table) {
            $table->uuid('flow_id')->primary();
            $table->foreign('flow_id')->references('id')->on('process_flow_nodes')->cascadeOnDelete();
            foreach (self::FIELDS as $key => $type) {
                $table->{$type}($key)->nullable();
            }
        });
        foreach (['temperature' => [-273.15, 3000], 'pressure' => [-0.101325, 1000], 'ph' => [0, 14], 'density' => [0, 100000], 'molecular_weight' => [0, 1000000], 'boiling_point' => [-273.15, 10000]] as $key => [$minimum,$maximum]) {
            DB::statement('ALTER TABLE process_flow_properties ADD CONSTRAINT flow_property_'.$key.'_range CHECK ('.$key.' BETWEEN '.$minimum.' AND '.$maximum.')');
        }
        DB::statement('ALTER TABLE process_flow_properties ADD CONSTRAINT flow_property_positive_density CHECK (density>0 AND molecular_weight>0)');
        DB::statement("ALTER TABLE process_flow_properties ADD CONSTRAINT flow_property_phase CHECK (phase IN ('gas','liquid','two_phase','solid'))");
        DB::statement("ALTER TABLE process_flow_properties ADD CONSTRAINT flow_property_insulation_chlorides CHECK (insulation_chlorides IN ('yes','no','unknown'))");
        Schema::create('process_flow_property_annotations', function (Blueprint $table) {
            $table->foreignUuid('flow_id')->constrained('process_flow_nodes')->cascadeOnDelete();
            $table->string('field_key', 64);
            $table->primary(['flow_id', 'field_key']);
            $table->foreign('field_key')->references('field_key')->on('process_flow_property_fields');
            $table->boolean('value_present');
            $table->boolean('source_present');
            $table->text('citation')->nullable();
            $table->text('conditions')->nullable();
            $table->unsignedBigInteger('source_by')->nullable();
            $table->timestampTz('source_at', 6)->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at', 6)->nullable();
        });
        DB::statement('ALTER TABLE process_flow_property_annotations ADD CONSTRAINT flow_property_source_pair CHECK ((source_present AND source_by IS NOT NULL AND source_at IS NOT NULL) OR (NOT source_present AND source_by IS NULL AND source_at IS NULL AND citation IS NULL AND conditions IS NULL))');
        DB::statement('ALTER TABLE process_flow_property_annotations ADD CONSTRAINT flow_property_verification_pair CHECK ((verified_by IS NULL)=(verified_at IS NULL))');
        DB::statement('ALTER TABLE process_flow_property_annotations ADD CONSTRAINT flow_property_verified_presence CHECK (verified_at IS NULL OR value_present)');
        Schema::table('process_flow_nodes', fn (Blueprint $table) => $table->uuid('accepted_calculation_id')->nullable());
        DB::statement('ALTER TABLE process_flow_calculations ADD CONSTRAINT flow_calculation_owner_unique UNIQUE (id,flow_id)');
        DB::statement('ALTER TABLE process_flow_nodes ADD CONSTRAINT flow_accepted_calculation_owner_fk FOREIGN KEY (accepted_calculation_id,id) REFERENCES process_flow_calculations(id,flow_id)');
        foreach (DB::table('process_flow_nodes')->orderBy('id')->cursor() as $flow) {
            $attributes = json_decode($flow->attributes, true, flags: JSON_THROW_ON_ERROR);
            $sources = json_decode($flow->sources, true, flags: JSON_THROW_ON_ERROR);
            $checks = json_decode($flow->checks, true, flags: JSON_THROW_ON_ERROR);
            $keys = array_unique([...array_keys($attributes), ...array_keys($sources), ...array_keys($checks)]);
            if (array_diff($keys, array_keys(self::FIELDS))) {
                throw new RuntimeException('Undeclared property prevents flow normalization: '.$flow->id);
            }
            DB::table('process_flow_properties')->insert(['flow_id' => $flow->id] + array_replace(array_fill_keys(array_keys(self::FIELDS), null), $attributes));
            foreach ($keys as $key) {
                $source = $sources[$key] ?? null;
                $check = $checks[$key] ?? null;
                DB::table('process_flow_property_annotations')->insert(['flow_id' => $flow->id, 'field_key' => $key, 'value_present' => array_key_exists($key, $attributes), 'source_present' => $source !== null, 'citation' => $source['citation'] ?? null, 'conditions' => $source['conditions'] ?? null, 'source_by' => $source['by'] ?? null, 'source_at' => $source['at'] ?? null, 'verified_by' => $check['by'] ?? null, 'verified_at' => $check['at'] ?? null]);
            }
            if ($flow->accepted_calculation !== null) {
                $accepted = json_decode($flow->accepted_calculation, true, flags: JSON_THROW_ON_ERROR);
                DB::table('process_flow_nodes')->where('id', $flow->id)->update(['accepted_calculation_id' => $accepted['id']]);
            }
        }
        DB::unprepared(FlowCardProjection::definition());
        if (DB::table('process_flow_nodes as f')->join('process_flow_cards as p', 'p.flow_id', '=', 'f.id')->whereRaw('f.attributes IS DISTINCT FROM p.attributes')->exists()) {
            throw new RuntimeException('Flow attributes changed during normalization.');
        }
        foreach (DB::table('process_flow_nodes as f')->join('process_flow_cards as p', 'p.flow_id', '=', 'f.id')->select(['f.id', 'f.sources as old_sources', 'p.sources', 'f.checks as old_checks', 'p.checks', 'f.accepted_calculation as old_accepted', 'p.accepted_calculation'])->cursor() as $row) {
            foreach (['sources', 'checks', 'accepted_calculation'] as $key) {
                $oldKey = match ($key) {
                    'sources' => 'old_sources','checks' => 'old_checks',default => 'old_accepted'
                };
                if ($this->canonical($row->{$oldKey}) != $this->canonical($row->{$key})) {
                    throw new RuntimeException('Flow '.$key.' changed during normalization: '.$row->id);
                }
            }
        }
        Schema::table('process_flow_nodes', fn (Blueprint $table) => $table->dropColumn(['attributes', 'sources', 'checks', 'accepted_calculation']));
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION reject_flow_calculation_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Flow calculation snapshots are immutable' USING ERRCODE='23514'; END;
            $$;
            CREATE TRIGGER flow_calculation_immutable BEFORE UPDATE OR DELETE ON process_flow_calculations
            FOR EACH ROW EXECUTE FUNCTION reject_flow_calculation_mutation();
            SQL);
    }

    private function canonical(?string $json): mixed
    {
        if ($json === null) {
            return null;
        }$value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $normalize = function ($value) use (&$normalize) {
            if (! is_array($value)) {
                return $value;
            }foreach ($value as $key => $item) {
                if ($key === 'at' && is_string($item)) {
                    $value[$key] = (new DateTimeImmutable($item))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
                } elseif (is_array($item)) {
                    $value[$key] = $normalize($item);
                }
            }

return $value;
        };

        return $normalize($value);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER flow_calculation_immutable ON process_flow_calculations');
        DB::statement('DROP FUNCTION reject_flow_calculation_mutation()');
        Schema::table('process_flow_nodes', function (Blueprint $table) {
            foreach (['attributes', 'sources', 'checks'] as $key) {
                $table->jsonb($key)->default('{}');
            }$table->jsonb('accepted_calculation')->nullable();
        });
        DB::statement('UPDATE process_flow_nodes f SET attributes=p.attributes,sources=p.sources,checks=p.checks,accepted_calculation=p.accepted_calculation FROM process_flow_cards p WHERE p.flow_id=f.id');
        DB::statement('DROP VIEW process_flow_cards');
        DB::statement('ALTER TABLE process_flow_nodes DROP CONSTRAINT flow_accepted_calculation_owner_fk');
        Schema::table('process_flow_nodes', fn (Blueprint $table) => $table->dropColumn('accepted_calculation_id'));
        DB::statement('ALTER TABLE process_flow_calculations DROP CONSTRAINT flow_calculation_owner_unique');
        foreach (['process_flow_property_annotations', 'process_flow_properties', 'process_flow_property_fields'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
