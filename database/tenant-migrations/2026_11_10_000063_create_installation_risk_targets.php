<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $fields = json_decode(file_get_contents(resource_path('rbi/risk-targets-v1.json')), true, flags: JSON_THROW_ON_ERROR)['fields'];
        Schema::create('installation_risk_targets', function (Blueprint $table) use ($fields) {
            $table->uuid('installation_id')->primary();
            $table->foreign('installation_id')->references('id')->on('installations')->restrictOnDelete();
            foreach ($fields as $field) {
                $table->double($field['key'])->nullable();
            }
            $table->timestampsTz();
        });
        foreach ($fields as $field) {
            $column = '"'.$field['key'].'"';
            DB::statement('ALTER TABLE installation_risk_targets ADD CHECK ('.$column.' BETWEEN '.$field['min'].' AND '.$field['max'].' AND '.$column." NOT IN ('NaN'::float8,'Infinity'::float8,'-Infinity'::float8))");
        }
        DB::statement('ALTER TABLE installation_risk_targets ADD CHECK (abs("H"*2-round(("H"*2)::numeric))<1e-9)');
        DB::statement('ALTER TABLE installation_risk_targets ADD CHECK ("planRec" <= "planMax")');
        Schema::create('installation_risk_target_overrides', function (Blueprint $table) {
            $table->uuid('installation_id');
            $table->string('field_key', 32);
            $table->primary(['installation_id', 'field_key']);
            $table->foreign('installation_id')->references('installation_id')->on('installation_risk_targets')->restrictOnDelete();
        });
        $keys = implode(',', array_map(fn ($field) => "'".$field['key']."'", $fields));
        $required = implode(',', array_map(fn ($field) => "'".$field['key']."'", array_filter($fields, fn ($field) => ! $field['nullable'])));
        DB::statement('ALTER TABLE installation_risk_target_overrides ADD CHECK(field_key IN ('.$keys.'))');
        DB::unprepared(<<<SQL
CREATE FUNCTION serialize_installation_risk_target_write() RETURNS trigger LANGUAGE plpgsql AS \$\$
BEGIN
 UPDATE installations SET version=version WHERE id=COALESCE(NEW.installation_id,OLD.installation_id);
 RETURN COALESCE(NEW,OLD);
END; \$\$;
CREATE FUNCTION guard_installation_risk_target_shape() RETURNS trigger LANGUAGE plpgsql AS \$\$
DECLARE owner_id uuid:=COALESCE(NEW.installation_id,OLD.installation_id); data jsonb; k text; present boolean;
BEGIN
 SELECT to_jsonb(t) INTO data FROM installation_risk_targets t WHERE t.installation_id=owner_id;
 IF data IS NULL THEN RETURN NULL; END IF;
 FOREACH k IN ARRAY ARRAY[$keys] LOOP
  SELECT EXISTS(SELECT 1 FROM installation_risk_target_overrides WHERE installation_id=owner_id AND field_key=k) INTO present;
  IF NOT present AND data->k <> 'null'::jsonb THEN RAISE EXCEPTION 'Risk target value needs an explicit override' USING ERRCODE='23514'; END IF;
  IF present AND k IN ($required) AND data->k = 'null'::jsonb THEN RAISE EXCEPTION 'Required risk target cannot be disabled' USING ERRCODE='23514'; END IF;
 END LOOP;
 RETURN NULL;
END; \$\$;
SQL);
        foreach (['installation_risk_targets', 'installation_risk_target_overrides'] as $table) {
            DB::statement('CREATE TRIGGER '.$table.'_serialize BEFORE INSERT OR UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION serialize_installation_risk_target_write()');
            DB::statement('CREATE CONSTRAINT TRIGGER '.$table.'_shape AFTER INSERT OR UPDATE OR DELETE ON '.$table.' DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_installation_risk_target_shape()');
        }
    }

    public function down(): void
    {
        if (DB::table('installation_risk_target_overrides')->exists()) {
            throw new RuntimeException('Cannot discard configured risk criteria.');
        }
        Schema::drop('installation_risk_target_overrides');
        Schema::drop('installation_risk_targets');
        DB::statement('DROP FUNCTION guard_installation_risk_target_shape()');
        DB::statement('DROP FUNCTION serialize_installation_risk_target_write()');
    }
};
