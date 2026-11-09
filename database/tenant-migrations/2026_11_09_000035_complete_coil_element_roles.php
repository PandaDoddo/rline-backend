<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceCheck(['inlet', 'inlet_boss', 'body', 'outlet_boss', 'outlet']);
    }

    public function down(): void
    {
        if (DB::table('equipment_components')->whereIn('coil_role', ['inlet_boss', 'outlet_boss'])->exists()) {
            throw new RuntimeException('Cannot remove populated coil boss roles.');
        }
        $this->replaceCheck(['inlet', 'body', 'outlet']);
    }

    private function replaceCheck(array $roles): void
    {
        $constraints = DB::select("SELECT conname FROM pg_constraint WHERE conrelid='equipment_components'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%coil_role%'");
        if (count($constraints) !== 1) {
            throw new RuntimeException('Expected exactly one coil role constraint.');
        }
        DB::statement('ALTER TABLE equipment_components DROP CONSTRAINT '.DB::connection()->getQueryGrammar()->wrap($constraints[0]->conname));
        DB::statement("ALTER TABLE equipment_components ADD CONSTRAINT component_coil_role_check CHECK (coil_role IS NULL OR (coil_role IN ('".implode("','", $roles)."') AND parent_id IS NOT NULL))");
    }
};
