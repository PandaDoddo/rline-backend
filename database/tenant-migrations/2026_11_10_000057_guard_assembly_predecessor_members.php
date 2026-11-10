<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('equipment_component_assemblies as a')->join('equipment_component_assembly_members as m', 'm.assembly_id', '=', 'a.id')->whereColumn('m.member_component_id', 'a.after_component_id')->exists()) {
            throw new RuntimeException('Assembly predecessor already belongs to its members; resolve the conflict before migrating.');
        }
        DB::unprepared($this->memberGuard(true));
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION guard_assembly_predecessor_members() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM equipment_component_assemblies a
                    JOIN equipment_component_assembly_members m ON m.assembly_id=a.id
                    WHERE a.id=NEW.id AND m.member_component_id=a.after_component_id
                ) THEN
                    RAISE EXCEPTION 'Assembly predecessor cannot belong to its members' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER assembly_predecessor_members_guard
                AFTER INSERT OR UPDATE ON equipment_component_assemblies
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_assembly_predecessor_members();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER assembly_predecessor_members_guard ON equipment_component_assemblies');
        DB::statement('DROP FUNCTION guard_assembly_predecessor_members()');
        DB::unprepared($this->memberGuard(false));
    }

    private function memberGuard(bool $locking): string
    {
        // Serialize member writes with predecessor updates on the aggregate row.
        $lock = $locking ? ' FOR UPDATE' : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION guard_component_assembly_member() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE owner_id uuid; predecessor_id uuid;
            BEGIN
                SELECT component_id,after_component_id INTO owner_id,predecessor_id
                    FROM equipment_component_assemblies WHERE id=NEW.assembly_id{$lock};
                IF NEW.member_component_id=predecessor_id OR NOT EXISTS (
                    WITH RECURSIVE descendants AS (
                        SELECT id FROM equipment_components WHERE parent_id=owner_id
                        UNION ALL SELECT c.id FROM equipment_components c JOIN descendants d ON c.parent_id=d.id
                    ) SELECT 1 FROM descendants WHERE id=NEW.member_component_id
                ) THEN
                    RAISE EXCEPTION 'Assembly member must be a descendant distinct from the predecessor' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END \$\$;
            SQL;
    }
};
