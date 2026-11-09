<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIELDS = ['pressure_test_mpa', 'pressure_design_mpa', 'pressure_allowed_mpa', 'pressure_operating_mpa', 'pressure_installation_mpa', 'temperature_design_c', 'temperature_allowed_c', 'temperature_max_c', 'temperature_min_c', 'temperature_mdmt_c', 'temperature_brittle_transition_c', 'temperature_shutdown_trip_c', 'temperature_shutdown_c', 'temperature_upset_c', 'relief_valve_present', 'medium', 'alternative_medium', 'volume_m3', 'flow_velocity_m_s', 'corrosion_allowance_mm', 'design_corrosion_mm_year'];

    public function up(): void
    {
        Schema::create('equipment_operating_scope_fields', function (Blueprint $table) {
            $table->string('field_key', 64)->primary();
        });
        DB::table('equipment_operating_scope_fields')->insert(array_map(fn ($key) => ['field_key' => $key], self::FIELDS));
        Schema::create('equipment_operating_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->uuid('component_id')->nullable();
            $table->string('kind', 8);
            $table->foreign(['component_id', 'equipment_id'])->references(['id', 'equipment_id'])->on('equipment_components')->restrictOnDelete();
            foreach (self::FIELDS as $key) {
                if (in_array($key, ['relief_valve_present', 'medium', 'alternative_medium'], true)) {
                    $table->text($key)->nullable();
                } else {
                    $table->double($key)->nullable();
                }
            }
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz(6);
        });
        DB::statement('ALTER TABLE equipment_operating_scopes ADD CONSTRAINT equipment_operating_scope_owner_unique UNIQUE NULLS NOT DISTINCT (equipment_id,kind,component_id)');
        DB::statement("ALTER TABLE equipment_operating_scopes ADD CONSTRAINT equipment_operating_scope_kind CHECK ((kind='tube' AND component_id IS NULL) OR (kind='coil' AND component_id IS NOT NULL))");
        DB::statement("ALTER TABLE equipment_operating_scopes ADD CONSTRAINT equipment_operating_scope_relief CHECK (relief_valve_present IN ('да','нет'))");
        DB::statement("ALTER TABLE equipment_operating_scopes ADD CONSTRAINT operating_scope_tube_only CHECK (kind='tube' OR (temperature_allowed_c IS NULL AND volume_m3 IS NULL AND flow_velocity_m_s IS NULL))");
        DB::statement("ALTER TABLE equipment_operating_scopes ADD CONSTRAINT operating_scope_coil_only CHECK (kind='coil' OR (pressure_installation_mpa IS NULL AND temperature_shutdown_trip_c IS NULL AND alternative_medium IS NULL AND corrosion_allowance_mm IS NULL AND design_corrosion_mm_year IS NULL))");
        foreach (self::FIELDS as $key) {
            if (in_array($key, ['relief_valve_present', 'medium', 'alternative_medium'], true)) {
                continue;
            }
            [$minimum,$maximum] = str_starts_with($key, 'temperature_') ? [-273.15, 3000] : (str_starts_with($key, 'pressure_') ? [0, 10000] : match ($key) {
                'volume_m3' => [0, 1e9],'flow_velocity_m_s','corrosion_allowance_mm' => [0, 100000],default => [0, 1000]
            });
            DB::statement('ALTER TABLE equipment_operating_scopes ADD CONSTRAINT operating_scope_'.$key.'_range CHECK ('.$key.' BETWEEN '.$minimum.' AND '.$maximum.')');
        }
        Schema::create('equipment_operating_scope_annotations', function (Blueprint $table) {
            $table->foreignUuid('scope_id')->constrained('equipment_operating_scopes')->cascadeOnDelete();
            $table->string('field_key', 64);
            $table->foreign('field_key')->references('field_key')->on('equipment_operating_scope_fields');
            $table->primary(['scope_id', 'field_key']);
            $table->boolean('value_present');
            $table->string('state', 20)->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at', 6)->nullable();
        });
        DB::statement("ALTER TABLE equipment_operating_scope_annotations ADD CONSTRAINT operating_scope_field_state CHECK (state IN ('unknown','not_applicable','not_present'))");
        DB::statement('ALTER TABLE equipment_operating_scope_annotations ADD CONSTRAINT operating_scope_verification_pair CHECK ((verified_by IS NULL)=(verified_at IS NULL))');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION guard_equipment_operating_scope() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='UPDATE' AND (NEW.equipment_id,NEW.kind,NEW.component_id) IS DISTINCT FROM (OLD.equipment_id,OLD.kind,OLD.component_id) THEN
                    RAISE EXCEPTION 'Operating scope ownership is immutable' USING ERRCODE='23514';
                END IF;
                IF NEW.kind='tube' AND NOT EXISTS (SELECT 1 FROM equipment WHERE id=NEW.equipment_id AND profile_code='ves_teplo') THEN
                    RAISE EXCEPTION 'Tube scope requires a heat exchanger profile' USING ERRCODE='23514';
                END IF;
                IF NEW.kind='coil' AND NOT EXISTS (SELECT 1 FROM equipment_components WHERE id=NEW.component_id AND equipment_id=NEW.equipment_id AND is_coil AND parent_id IS NULL) THEN
                    RAISE EXCEPTION 'Coil scope requires its own root coil component' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER equipment_operating_scope_guard BEFORE INSERT OR UPDATE ON equipment_operating_scopes FOR EACH ROW EXECUTE FUNCTION guard_equipment_operating_scope();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_operating_scope_annotations');
        Schema::dropIfExists('equipment_operating_scopes');
        DB::statement('DROP FUNCTION guard_equipment_operating_scope()');
        Schema::dropIfExists('equipment_operating_scope_fields');
    }
};
