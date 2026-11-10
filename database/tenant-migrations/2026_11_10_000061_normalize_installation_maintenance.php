<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = json_decode(file_get_contents(resource_path('schemas/installation-maintenance-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        Schema::table('installation_maintenance_records', function (Blueprint $table) {
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at', 6)->nullable();
            $table->char('verification_signature', 64)->nullable();
            $table->unique(['id', 'kind'], 'maintenance_record_kind_unique');
            $table->unique(['id', 'installation_id', 'kind'], 'maintenance_record_owner_kind_unique');
        });
        DB::statement("ALTER TABLE installation_maintenance_records ADD CONSTRAINT maintenance_record_kind CHECK(kind IN ('mri','repair','stop')), ADD CONSTRAINT maintenance_reason_length CHECK(length(reason)<=5000), ADD CONSTRAINT maintenance_verification_pair CHECK((verified_by IS NULL)=(verified_at IS NULL)), ADD CONSTRAINT maintenance_verified_actor CHECK(verified_by>0), ADD CONSTRAINT maintenance_verification_signature CHECK(verification_signature IS NULL OR (verified_at IS NOT NULL AND verification_signature ~ '^[0-9a-f]{64}$'))");
        Schema::create('installation_maintenance_fields', function (Blueprint $table) {
            $table->string('kind', 10);
            $table->string('field_key', 32);
            $table->boolean('required_for_verification');
            $table->primary(['kind', 'field_key']);
        });
        foreach ($schema['kinds'] as $kind => $definition) {
            foreach ($definition['fields'] as $field => $type) {
                DB::table('installation_maintenance_fields')->insert(['kind' => $kind, 'field_key' => $field, 'required_for_verification' => in_array($field, $definition['required'], true)]);
            }
            Schema::create($definition['table'], function (Blueprint $table) use ($kind, $definition) {
                $table->uuid('record_id')->primary();
                $table->string('kind', 10)->default($kind);
                if ($kind === 'mri') {
                    $table->uuid('installation_id');
                    $table->boolean('active_slot')->nullable();
                }
                foreach ($definition['fields'] as $key => $type) {
                    if ($key === 'reason') {
                        continue;
                    }
                    match ($type) {
                        'date' => $table->date($key)->nullable(),'integer' => $table->integer($key)->nullable(),'timestamp' => $table->timestampTz($key, 6)->nullable(),default => $table->string($key, 32)->nullable()
                    };
                }
                if ($kind === 'mri') {
                    $table->foreign(['record_id', 'installation_id', 'kind'], 'maintenance_mri_owner_fk')->references(['id', 'installation_id', 'kind'])->on('installation_maintenance_records')->cascadeOnDelete();
                } else {
                    $table->foreign(['record_id', 'kind'], 'maintenance_'.$kind.'_owner_fk')->references(['id', 'kind'])->on('installation_maintenance_records')->cascadeOnDelete();
                }
            });
            DB::statement('ALTER TABLE '.$definition['table'].' ADD CONSTRAINT maintenance_'.$kind."_kind CHECK(kind='".$kind."')");
        }
        DB::statement('ALTER TABLE installation_mri_parameters ADD CHECK(interval_months BETWEEN 1 AND 1200), ADD CHECK(active_slot IS NULL OR active_slot), ADD CONSTRAINT maintenance_active_mri_date UNIQUE(installation_id,effective_date,active_slot) DEFERRABLE INITIALLY DEFERRED');
        DB::statement("ALTER TABLE installation_repair_parameters ADD CHECK(repair_kind IN ('capital','extended_medium','medium')), ADD CHECK(ends_on>=starts_on)");
        DB::statement('ALTER TABLE installation_stop_parameters ADD CHECK(ends_at>=starts_at)');
        foreach ($schema['kinds'] as $definition) {
            foreach ($definition['fields'] as $key => $type) {
                if ($type === 'date') {
                    DB::statement('ALTER TABLE '.$definition['table'].' ADD CHECK('.$key." BETWEEN DATE '0001-01-01' AND DATE '9999-12-31')");
                }
                if ($type === 'timestamp') {
                    DB::statement('ALTER TABLE '.$definition['table'].' ADD CHECK('.$key." BETWEEN TIMESTAMPTZ '0001-01-01 00:00:00+00' AND TIMESTAMPTZ '9999-12-31 23:59:59.999999+00')");
                }
            }
        }
        Schema::create('installation_maintenance_presence', function (Blueprint $table) {
            $table->uuid('record_id');
            $table->string('kind', 10);
            $table->string('field_key', 32);
            $table->primary(['record_id', 'field_key']);
            $table->foreign(['record_id', 'kind'], 'maintenance_presence_owner_fk')->references(['id', 'kind'])->on('installation_maintenance_records')->cascadeOnDelete();
            $table->foreign(['kind', 'field_key'])->references(['kind', 'field_key'])->on('installation_maintenance_fields');
        });
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION sync_maintenance_mri_slot() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                SELECT installation_id,CASE WHEN deleted_at IS NULL THEN TRUE ELSE NULL END INTO NEW.installation_id,NEW.active_slot
                    FROM installation_maintenance_records WHERE id=NEW.record_id AND kind='mri';
                IF NOT FOUND THEN RAISE EXCEPTION 'MRI owner is missing' USING ERRCODE='23503'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER maintenance_mri_slot BEFORE INSERT OR UPDATE ON installation_mri_parameters FOR EACH ROW EXECUTE FUNCTION sync_maintenance_mri_slot();
            CREATE FUNCTION sync_maintenance_record_slot() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.kind='mri' THEN UPDATE installation_mri_parameters SET active_slot=CASE WHEN NEW.deleted_at IS NULL THEN TRUE ELSE NULL END WHERE record_id=NEW.id; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER maintenance_record_slot AFTER UPDATE OF deleted_at ON installation_maintenance_records FOR EACH ROW EXECUTE FUNCTION sync_maintenance_record_slot();
            SQL);
        foreach (DB::table('installation_maintenance_records')->orderBy('id')->cursor() as $record) {
            $definition = $schema['kinds'][$record->kind] ?? throw new RuntimeException('Unknown maintenance kind prevents normalization.');
            $values = json_decode($record->values, true, flags: JSON_THROW_ON_ERROR);
            if (array_diff(array_keys($values), array_keys($definition['fields']))) {
                throw new RuntimeException('Unknown maintenance field prevents normalization: '.$record->id);
            }
            $check = json_decode($record->check ?? 'null', true, flags: JSON_THROW_ON_ERROR);
            DB::table('installation_maintenance_records')->where('id', $record->id)->update(['reason' => $values['reason'] ?? null, 'verified_by' => $check['by'] ?? null, 'verified_at' => $check['at'] ?? null]);
            $parameters = ['record_id' => $record->id];
            foreach ($definition['fields'] as $key => $type) {
                if ($key !== 'reason') {
                    $parameters[$key] = $values[$key] ?? null;
                }
            }
            DB::table($definition['table'])->insert($parameters);
            if ($values !== []) {
                DB::table('installation_maintenance_presence')->insert(array_map(fn ($key) => ['record_id' => $record->id, 'kind' => $record->kind, 'field_key' => $key], array_keys($values)));
            }
        }
        DB::unprepared(file_get_contents(resource_path('schemas/installation-maintenance-cards-v1.sql')));
        if (DB::table('installation_maintenance_records as r')->join('installation_maintenance_cards as c', 'c.record_id', '=', 'r.id')->whereRaw("r.values IS DISTINCT FROM c.values OR coalesce(r.check,'null'::jsonb) IS DISTINCT FROM coalesce(c.check,'null'::jsonb)")->exists()) {
            throw new RuntimeException('Maintenance values or verification changed during normalization.');
        }
        Schema::table('installation_maintenance_records', fn (Blueprint $table) => $table->dropColumn(['values', 'check']));
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION installation_maintenance_value_signature(uuid) RETURNS text LANGUAGE sql STABLE AS $$
                SELECT encode(sha256(convert_to(c."values"::text,'UTF8')),'hex') FROM installation_maintenance_cards c WHERE c.record_id=$1
            $$;
            CREATE FUNCTION guard_maintenance_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (OLD.id,OLD.installation_id,OLD.kind) IS DISTINCT FROM (NEW.id,NEW.installation_id,NEW.kind) THEN
                    RAISE EXCEPTION 'Maintenance identity is immutable' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER maintenance_identity BEFORE UPDATE ON installation_maintenance_records FOR EACH ROW EXECUTE FUNCTION guard_maintenance_identity();
            CREATE FUNCTION guard_maintenance_shape() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE owner_id uuid; r record; p jsonb; f record; present boolean;
            BEGIN
                IF TG_TABLE_NAME='installation_maintenance_records' THEN owner_id:=CASE WHEN TG_OP='DELETE' THEN OLD.id ELSE NEW.id END;
                ELSE owner_id:=CASE WHEN TG_OP='DELETE' THEN OLD.record_id ELSE NEW.record_id END; END IF;
                SELECT * INTO r FROM installation_maintenance_records WHERE id=owner_id;
                IF NOT FOUND THEN RETURN NULL; END IF;
                IF r.kind='mri' THEN SELECT to_jsonb(v) INTO p FROM installation_mri_parameters v WHERE record_id=owner_id;
                ELSIF r.kind='repair' THEN SELECT to_jsonb(v) INTO p FROM installation_repair_parameters v WHERE record_id=owner_id;
                ELSE SELECT to_jsonb(v) INTO p FROM installation_stop_parameters v WHERE record_id=owner_id; END IF;
                IF p IS NULL THEN RAISE EXCEPTION 'Typed maintenance parameters are required' USING ERRCODE='23514'; END IF;
                p:=p||jsonb_build_object('reason',r.reason);
                FOR f IN SELECT * FROM installation_maintenance_fields WHERE kind=r.kind LOOP
                    SELECT EXISTS(SELECT 1 FROM installation_maintenance_presence WHERE record_id=owner_id AND field_key=f.field_key) INTO present;
                    IF NOT present AND p->f.field_key IS NOT NULL AND p->f.field_key<>'null'::jsonb THEN
                        RAISE EXCEPTION 'Maintenance value requires presence: %',f.field_key USING ERRCODE='23514';
                    END IF;
                    IF r.verified_at IS NOT NULL AND f.required_for_verification AND (p->f.field_key IS NULL OR p->f.field_key='null'::jsonb) THEN
                        RAISE EXCEPTION 'Incomplete maintenance record cannot be verified' USING ERRCODE='23514';
                    END IF;
                END LOOP;
                IF r.verified_at IS NOT NULL AND r.verification_signature IS DISTINCT FROM installation_maintenance_value_signature(owner_id) THEN
                    RAISE EXCEPTION 'Verified maintenance input changed; explicitly unlock or reverify' USING ERRCODE='23514';
                END IF;
                IF r.kind='stop' AND r.deleted_at IS NULL THEN
                    IF TG_TABLE_NAME='installation_maintenance_records' THEN
                        -- A real MVCC write also serializes direct SQL under repeatable-read.
                        UPDATE installations SET no_stops=no_stops WHERE id=r.installation_id;
                    END IF;
                    IF EXISTS(SELECT 1 FROM installations WHERE id=r.installation_id AND no_stops IS TRUE) THEN
                        RAISE EXCEPTION 'Active stops conflict with no-stops assertion' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER maintenance_record_shape AFTER INSERT OR UPDATE OR DELETE ON installation_maintenance_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_maintenance_shape();
            CREATE CONSTRAINT TRIGGER maintenance_mri_shape AFTER INSERT OR UPDATE OR DELETE ON installation_mri_parameters DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_maintenance_shape();
            CREATE CONSTRAINT TRIGGER maintenance_repair_shape AFTER INSERT OR UPDATE OR DELETE ON installation_repair_parameters DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_maintenance_shape();
            CREATE CONSTRAINT TRIGGER maintenance_stop_shape AFTER INSERT OR UPDATE OR DELETE ON installation_stop_parameters DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_maintenance_shape();
            CREATE CONSTRAINT TRIGGER maintenance_presence_shape AFTER INSERT OR UPDATE OR DELETE ON installation_maintenance_presence DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_maintenance_shape();
            CREATE FUNCTION guard_installation_no_stops() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS(SELECT 1 FROM installations WHERE id=NEW.id AND no_stops IS TRUE)
                    AND EXISTS(SELECT 1 FROM installation_maintenance_records WHERE installation_id=NEW.id AND kind='stop' AND deleted_at IS NULL) THEN
                    RAISE EXCEPTION 'No-stops assertion conflicts with active stop records' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER installation_no_stops_guard AFTER UPDATE ON installations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (OLD.no_stops IS DISTINCT FROM NEW.no_stops) EXECUTE FUNCTION guard_installation_no_stops();
            UPDATE installation_maintenance_records SET verification_signature=CASE WHEN verified_at IS NULL THEN NULL ELSE installation_maintenance_value_signature(id) END;
            SQL);
    }

    public function down(): void
    {
        foreach (['installation_maintenance_records' => 'maintenance_record_shape', 'installation_mri_parameters' => 'maintenance_mri_shape', 'installation_repair_parameters' => 'maintenance_repair_shape', 'installation_stop_parameters' => 'maintenance_stop_shape', 'installation_maintenance_presence' => 'maintenance_presence_shape', 'installations' => 'installation_no_stops_guard'] as $table => $trigger) {
            DB::statement('DROP TRIGGER '.$trigger.' ON '.$table);
        }
        DB::statement('DROP TRIGGER maintenance_identity ON installation_maintenance_records');
        DB::statement('DROP TRIGGER maintenance_record_slot ON installation_maintenance_records');
        DB::statement('DROP TRIGGER maintenance_mri_slot ON installation_mri_parameters');
        foreach (['guard_maintenance_shape', 'guard_maintenance_identity', 'guard_installation_no_stops', 'sync_maintenance_record_slot', 'sync_maintenance_mri_slot'] as $function) {
            DB::statement('DROP FUNCTION '.$function.'()');
        }
        DB::statement('DROP FUNCTION installation_maintenance_value_signature(uuid)');
        Schema::table('installation_maintenance_records', function (Blueprint $table) {
            $table->jsonb('values')->default('{}');
            $table->jsonb('check')->nullable();
        });
        DB::statement('UPDATE installation_maintenance_records r SET "values"=c."values","check"=c."check" FROM installation_maintenance_cards c WHERE c.record_id=r.id');
        DB::statement('DROP VIEW installation_maintenance_cards');
        foreach (['installation_maintenance_presence', 'installation_mri_parameters', 'installation_repair_parameters', 'installation_stop_parameters', 'installation_maintenance_fields'] as $table) {
            Schema::drop($table);
        }
        Schema::table('installation_maintenance_records', function (Blueprint $table) {
            $table->dropUnique('maintenance_record_kind_unique');
            $table->dropUnique('maintenance_record_owner_kind_unique');
            $table->dropColumn(['reason', 'verified_by', 'verified_at', 'verification_signature']);
        });
        DB::statement('ALTER TABLE installation_maintenance_records DROP CONSTRAINT maintenance_record_kind');
    }
};
