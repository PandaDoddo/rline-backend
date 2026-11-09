<?php

use App\Modules\Equipment\Domain\ComponentTree;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE equipment_components IN SHARE ROW EXCLUSIVE MODE');
        DB::table('equipment')->orderBy('id')->chunk(100, function ($equipment) {
            $groups = DB::table('equipment_components')->whereIn('equipment_id', $equipment->pluck('id'))->get()->groupBy('equipment_id');
            foreach ($groups as $rows) {
                $tree = new ComponentTree($rows->map(fn ($row) => (array) $row)->all());
                foreach ($rows as $row) {
                    $tree->lineage($row->id);
                }
            }
        });
        DB::statement('ALTER TABLE equipment_components ADD CONSTRAINT equipment_components_owner_unique UNIQUE (id,equipment_id)');
        DB::statement('ALTER TABLE equipment_components ADD CONSTRAINT equipment_components_parent_owner_fk FOREIGN KEY (parent_id,equipment_id) REFERENCES equipment_components(id,equipment_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE equipment_components ADD CONSTRAINT equipment_components_parent_kind_check CHECK ((parent_id IS NULL AND kind<>'element') OR (parent_id IS NOT NULL AND kind='element'))");
        DB::statement("ALTER TABLE equipment_components ADD CONSTRAINT equipment_components_coil_check CHECK ((kind='coil')=is_coil AND (NOT is_coil OR parent_id IS NULL))");
        DB::statement(<<<'SQL'
CREATE FUNCTION guard_equipment_component_ancestry() RETURNS trigger LANGUAGE plpgsql AS $guard$
DECLARE has_cycle boolean; ancestor_count integer;
BEGIN
    IF TG_OP='UPDATE' THEN
        IF NEW.parent_id IS DISTINCT FROM OLD.parent_id OR NEW.kind<>OLD.kind OR NEW.is_coil<>OLD.is_coil OR NEW.equipment_id<>OLD.equipment_id THEN
            RAISE EXCEPTION 'Component identity, parent and ownership are immutable' USING ERRCODE='23514';
        END IF;
    END IF;
    WITH RECURSIVE ancestry AS (
        SELECT p.id,p.parent_id,ARRAY[NEW.id,p.id] path,p.id=NEW.id cycle,1 depth
        FROM equipment_components p WHERE p.id=NEW.parent_id
        UNION ALL
        SELECT p.id,p.parent_id,a.path||p.id,p.id=ANY(a.path),a.depth+1
        FROM ancestry a JOIN equipment_components p ON p.id=a.parent_id WHERE NOT a.cycle AND a.depth<8
    ) SELECT bool_or(cycle),MAX(depth) INTO has_cycle,ancestor_count FROM ancestry;
    IF has_cycle OR ancestor_count>=8 THEN
        RAISE EXCEPTION 'Component hierarchy cycle or depth greater than eight' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END
$guard$
SQL);
        DB::statement('CREATE TRIGGER equipment_component_ancestry_guard BEFORE INSERT OR UPDATE OF parent_id,kind,is_coil,equipment_id ON equipment_components FOR EACH ROW EXECUTE FUNCTION guard_equipment_component_ancestry()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER equipment_component_ancestry_guard ON equipment_components');
        DB::statement('DROP FUNCTION guard_equipment_component_ancestry()');
        foreach (['equipment_components_parent_owner_fk', 'equipment_components_owner_unique', 'equipment_components_parent_kind_check', 'equipment_components_coil_check'] as $constraint) {
            DB::statement('ALTER TABLE equipment_components DROP CONSTRAINT '.$constraint);
        }
    }
};
