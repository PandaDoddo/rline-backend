<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FIELDS = ['diameter_mm', 'length_mm', 'nominal_thickness_mm', 'rejection_passport_mm', 'rejection_epb_mm'];

    public function up(): void
    {
        DB::statement('LOCK TABLE equipment_components IN ACCESS EXCLUSIVE MODE');
        foreach (self::FIELDS as $field) {
            DB::statement('ALTER TABLE equipment_components ALTER COLUMN '.$field.' TYPE double precision USING '.$field.'::double precision');
            DB::statement('ALTER TABLE equipment_components ADD CONSTRAINT component_'.$field.'_positive CHECK ('.$field.'>0 AND '.$field."<'Infinity'::float8)");
        }
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE equipment_components IN ACCESS EXCLUSIVE MODE');
        foreach (self::FIELDS as $field) {
            if (DB::table('equipment_components')->whereNotNull($field)->whereRaw('round('.$field.'::numeric,4)::double precision IS DISTINCT FROM '.$field.' OR '.$field.'>=1e10')->exists()) {
                throw new RuntimeException('Rollback would round component input values: '.$field);
            }
        }
        foreach (self::FIELDS as $field) {
            DB::statement('ALTER TABLE equipment_components DROP CONSTRAINT component_'.$field.'_positive');
            DB::statement('ALTER TABLE equipment_components ALTER COLUMN '.$field.' TYPE numeric(14,4) USING '.$field.'::numeric(14,4)');
        }
    }
};
