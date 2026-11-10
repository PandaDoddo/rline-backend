<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIELDS = ['external_coating_present', 'external_coating_applied_on', 'external_coating_life_years', 'external_coating_quality', 'external_coating_application_quality', 'external_coating_notes',
        'insulation_present', 'insulation_chlorides', 'insulation_notes', 'ground_location', 'ground_air_transition', 'moisture_accumulation', 'construction_complexity', 'corrosion_monitoring', 'atmospheric_conditions'];

    public function up(): void
    {
        $schema = json_decode(file_get_contents(resource_path('schemas/equipment-operating-scopes-v2.json')), true, flags: JSON_THROW_ON_ERROR);
        if ($schema['version'] !== 'equipment-operating-scopes-v2') {
            throw new RuntimeException('Migration requires frozen operating scope schema v2.');
        }
        $fields = array_column($schema['fields'], null, 'key');
        Schema::table('equipment_operating_scopes', function (Blueprint $table) {
            foreach (self::FIELDS as $key) {
                match ($key) {
                    'external_coating_applied_on' => $table->date($key)->nullable(),
                    'external_coating_life_years' => $table->double($key)->nullable(),
                    default => $table->text($key)->nullable(),
                };
            }
        });
        foreach (self::FIELDS as $key) {
            DB::table('equipment_operating_scope_fields')->insert(['field_key' => $key]);
            if ($key === 'external_coating_applied_on') {
                DB::statement("ALTER TABLE equipment_operating_scopes ADD CHECK (external_coating_applied_on BETWEEN DATE '0001-01-01' AND DATE '9999-12-31')");
            } elseif ($key === 'external_coating_life_years') {
                DB::statement('ALTER TABLE equipment_operating_scopes ADD CHECK (external_coating_life_years BETWEEN 0 AND 200)');
            } else {
                DB::statement('ALTER TABLE equipment_operating_scopes ADD CHECK (char_length('.$key.')<=2000)');
                if (isset($fields[$key]['options'])) {
                    $choices = implode(',', array_map(fn ($value) => DB::getPdo()->quote($value), $fields[$key]['options']));
                    DB::statement('ALTER TABLE equipment_operating_scopes ADD CHECK ('.$key.' IN ('.$choices.'))');
                }
            }
        }
        DB::statement('ALTER TABLE equipment_operating_scopes DROP CONSTRAINT operating_scope_tube_only');
        DB::statement('ALTER TABLE equipment_operating_scopes DROP CONSTRAINT operating_scope_coil_only');
        DB::statement("ALTER TABLE equipment_operating_scopes ADD CONSTRAINT operating_scope_tube_only CHECK (kind='tube' OR (temperature_allowed_c IS NULL AND volume_m3 IS NULL))");
        DB::statement("ALTER TABLE equipment_operating_scopes ADD CONSTRAINT operating_scope_coil_only CHECK (kind='coil' OR (pressure_installation_mpa IS NULL AND temperature_shutdown_trip_c IS NULL AND alternative_medium IS NULL))");
        Schema::table('equipment_operating_scope_annotations', fn (Blueprint $table) => $table->string('precision', 8)->nullable());
        DB::statement("ALTER TABLE equipment_operating_scope_annotations ADD CONSTRAINT scope_date_precision_kind CHECK (precision IS NULL OR (field_key='external_coating_applied_on' AND precision IN ('day','year')))");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_operating_scope_annotation_values() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner_id uuid;
BEGIN
 IF TG_TABLE_NAME='equipment_operating_scope_annotations' THEN
  IF NEW.state IS NULL AND NEW.precision IS NULL THEN RETURN NULL; END IF;
  owner_id:=NEW.scope_id;
 ELSE owner_id:=NEW.id;
 END IF;
 IF EXISTS(SELECT 1 FROM equipment_operating_scopes s JOIN equipment_operating_scope_annotations a ON a.scope_id=s.id
   WHERE s.id=owner_id AND a.state IS NOT NULL AND (to_jsonb(s)->a.field_key) IS DISTINCT FROM 'null'::jsonb) THEN
  RAISE EXCEPTION 'A qualified unknown or absent scope value must be null' USING ERRCODE='23514';
 END IF;
 IF EXISTS(SELECT 1 FROM equipment_operating_scopes s JOIN equipment_operating_scope_annotations a ON a.scope_id=s.id
   WHERE s.id=owner_id AND a.field_key='external_coating_applied_on' AND a.precision='year' AND s.external_coating_applied_on IS NOT NULL
   AND to_char(s.external_coating_applied_on,'MM-DD')<>'01-01') THEN
  RAISE EXCEPTION 'A year-only coating date must be represented as January 1' USING ERRCODE='23514';
 END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER scope_v2_annotation_values AFTER INSERT OR UPDATE ON equipment_operating_scope_annotations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_operating_scope_annotation_values();
CREATE CONSTRAINT TRIGGER scope_v2_scope_values AFTER INSERT OR UPDATE ON equipment_operating_scopes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_operating_scope_annotation_values();
SQL);
    }

    public function down(): void
    {
        $newValues = DB::table('equipment_operating_scopes')->where(function ($query) {
            foreach (self::FIELDS as $key) {
                $query->orWhereNotNull($key);
            }
            $query->orWhere(fn ($query) => $query->where('kind', 'coil')->whereNotNull('flow_velocity_m_s'));
            $query->orWhere(fn ($query) => $query->where('kind', 'tube')->where(fn ($query) => $query->whereNotNull('corrosion_allowance_mm')->orWhereNotNull('design_corrosion_mm_year')));
        })->exists();
        $annotations = DB::table('equipment_operating_scope_annotations as a')->join('equipment_operating_scopes as s', 's.id', '=', 'a.scope_id')->where(function ($query) {
            $query->whereIn('a.field_key', self::FIELDS)->orWhereNotNull('a.precision');
            $query->orWhere(fn ($query) => $query->where('s.kind', 'coil')->where('a.field_key', 'flow_velocity_m_s'));
            $query->orWhere(fn ($query) => $query->where('s.kind', 'tube')->whereIn('a.field_key', ['corrosion_allowance_mm', 'design_corrosion_mm_year']));
        })->exists();
        if ($newValues || $annotations) {
            throw new RuntimeException('Cannot discard independent scope protection, RBI values or date precision.');
        }
        DB::statement('DROP TRIGGER scope_v2_annotation_values ON equipment_operating_scope_annotations');
        DB::statement('DROP TRIGGER scope_v2_scope_values ON equipment_operating_scopes');
        DB::statement('DROP FUNCTION guard_operating_scope_annotation_values()');
        Schema::table('equipment_operating_scope_annotations', function (Blueprint $table) {
            $table->dropColumn('precision');
        });
        DB::statement('ALTER TABLE equipment_operating_scopes DROP CONSTRAINT operating_scope_tube_only');
        DB::statement('ALTER TABLE equipment_operating_scopes DROP CONSTRAINT operating_scope_coil_only');
        DB::statement("ALTER TABLE equipment_operating_scopes ADD CONSTRAINT operating_scope_tube_only CHECK (kind='tube' OR (temperature_allowed_c IS NULL AND volume_m3 IS NULL AND flow_velocity_m_s IS NULL))");
        DB::statement("ALTER TABLE equipment_operating_scopes ADD CONSTRAINT operating_scope_coil_only CHECK (kind='coil' OR (pressure_installation_mpa IS NULL AND temperature_shutdown_trip_c IS NULL AND alternative_medium IS NULL AND corrosion_allowance_mm IS NULL AND design_corrosion_mm_year IS NULL))");
        DB::table('equipment_operating_scope_fields')->whereIn('field_key', self::FIELDS)->delete();
        Schema::table('equipment_operating_scopes', fn (Blueprint $table) => $table->dropColumn(self::FIELDS));
    }
};
