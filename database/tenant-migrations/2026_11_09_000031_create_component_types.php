<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_component_types', function (Blueprint $table) {
            $table->string('code', 60)->primary();
            $table->string('name');
            $table->string('root_kind', 30)->nullable();
            $table->boolean('element_allowed');
            $table->unsignedSmallInteger('position');
        });
        // Frozen vocabulary from the old component editor. Unknown existing types stay NULL.
        $types = [
            ['head', 'днище', 'head', true],
            ['shell', 'корпус (обечайка)', 'shell', false],
            ['nozzle', 'штуцер', 'nozzle', false],
            ['external_coil', 'змеевик наружный', 'coil', false],
            ['boss', 'бобышка', 'other', true],
            ['internal_coil', 'змеевик внутренний', 'coil', false],
            ['pipe', 'труба', 'pipe', true],
            ['branch_pipe', 'врезка (труба)', 'other', true],
            ['elbow', 'отвод', 'other', true],
            ['reducer', 'переход', 'other', true],
            ['tee', 'тройник', 'other', true],
            ['cap', 'заглушка', 'other', true],
            ['spectacle_blind', 'заглушка поворотная (очковая)', 'other', true],
            ['flange', 'фланец', 'other', true],
            ['valve', 'арматура', 'other', false],
            ['bellows_expansion_joint', 'сильфонный компенсатор', 'other', true],
            ['tube_bundle', 'трубный пучок', 'tube_bundle', false],
            ['valve_body', 'корпус', 'other', false],
            ['valve_seat', 'седло', 'other', false],
            ['valve_disc', 'диск / золотник', 'other', false],
            ['spring', 'пружина', 'other', false],
            ['cover', 'крышка', 'other', true],
            ['bonnet', 'колпак', 'other', false],
            ['adjusting_screw', 'регулировочный винт', 'other', false],
            ['disc_holder', 'дискодержатель', 'other', false],
            ['stem', 'шток', 'other', false],
            ['lens_expansion_joint', 'компенсатор линзовый', 'other', true],
            ['heat_exchange_section', 'теплообменная секция', 'other', false],
            ['tube_header', 'коллектор трубный', 'other', false],
            ['louver', 'жалюзийное устройство', 'other', false],
            ['fan_diffuser', 'диффузор вентилятора', 'other', false],
            ['flanged_cap', 'заглушка фланцевая', null, true],
            ['coil_body', 'змеевик', null, true],
            ['lens', 'линза', null, true],
            ['tube_sheet', 'трубная решётка (доска)', null, true],
            ['heat_exchange_tube', 'теплообменная трубка', null, true],
            ['shell_course', 'пояс', null, true],
            ['return_bend', 'калач', null, true],
        ];
        DB::table('equipment_component_types')->insert(array_map(fn ($row, $position) => ['code' => $row[0], 'name' => $row[1], 'root_kind' => $row[2], 'element_allowed' => $row[3], 'position' => $position], $types, array_keys($types)));
        Schema::table('equipment_components', function (Blueprint $table) {
            $table->string('type_code', 60)->nullable();
            $table->foreign('type_code')->references('code')->on('equipment_component_types')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_component_type() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE definition equipment_component_types%ROWTYPE;
BEGIN
 IF NEW.type_code IS NULL THEN RETURN NEW; END IF;
 SELECT * INTO definition FROM equipment_component_types WHERE code=NEW.type_code;
 IF NOT FOUND THEN RETURN NEW; END IF; -- The FK reports an unknown type.
 IF (NEW.parent_id IS NULL AND (definition.root_kind IS NULL OR NEW.kind<>definition.root_kind))
    OR (NEW.parent_id IS NOT NULL AND (NOT definition.element_allowed OR NEW.kind<>'element')) THEN
  RAISE EXCEPTION 'Component type is incompatible with component kind or placement' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER component_type BEFORE INSERT OR UPDATE OF type_code,kind,parent_id ON equipment_components
FOR EACH ROW EXECUTE FUNCTION guard_component_type();
SQL);
    }

    public function down(): void
    {
        if (DB::table('equipment_components')->whereNotNull('type_code')->exists()) {
            throw new RuntimeException('Cannot remove populated component types.');
        }
        DB::statement('DROP TRIGGER component_type ON equipment_components');
        DB::statement('DROP FUNCTION guard_component_type()');
        Schema::table('equipment_components', function (Blueprint $table) {
            $table->dropForeign(['type_code']);
            $table->dropColumn('type_code');
        });
        Schema::dropIfExists('equipment_component_types');
    }
};
