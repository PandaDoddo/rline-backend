<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ORIGINAL = ['греющий кабель', 'теплоспутник', 'змеевик наружный', 'змеевик внутренний'];

    public function up(): void
    {
        $this->replace([...self::ORIGINAL, 'паровой спутник', 'рубашка', 'электрообогрев']);
    }

    private function replace(array $options): void
    {
        $constraints = DB::select("SELECT conname FROM pg_constraint WHERE conrelid='equipment_constructions'::regclass AND contype='c' AND conkey=ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid='equipment_constructions'::regclass AND attname='heating_type')]::smallint[]");
        if (count($constraints) !== 1) {
            throw new RuntimeException('Expected one declared heating type constraint.');
        }
        DB::statement('ALTER TABLE equipment_constructions DROP CONSTRAINT "'.str_replace('"', '""', $constraints[0]->conname).'"');
        $quoted = array_map(fn ($value) => DB::getPdo()->quote($value), $options);
        DB::statement('ALTER TABLE equipment_constructions ADD CONSTRAINT equipment_constructions_heating_type_check CHECK (heating_type IN ('.implode(',', $quoted).'))');
    }

    public function down(): void
    {
        if (DB::table('equipment_constructions')->whereNotIn('heating_type', self::ORIGINAL)->exists()) {
            throw new RuntimeException('Cannot discard a known heating type.');
        }
        $this->replace(self::ORIGINAL);
    }
};
