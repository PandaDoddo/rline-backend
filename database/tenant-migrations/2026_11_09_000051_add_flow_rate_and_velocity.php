<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIELDS = ['flow_rate_t_h' => 1e12, 'velocity_m_s' => 100000];

    public function up(): void
    {
        foreach (self::FIELDS as $field => $maximum) {
            Schema::table('process_flow_properties', fn (Blueprint $table) => $table->double($field)->nullable());
            DB::statement("ALTER TABLE process_flow_properties ADD CONSTRAINT flow_property_{$field}_range CHECK ({$field} BETWEEN 0 AND {$maximum} AND {$field} NOT IN ('NaN'::float8,'Infinity'::float8,'-Infinity'::float8))");
            DB::table('process_flow_property_fields')->insert(['field_key' => $field, 'value_type' => 'double']);
        }
    }

    public function down(): void
    {
        foreach (self::FIELDS as $field => $maximum) {
            if (DB::table('process_flow_properties')->whereNotNull($field)->exists() || DB::table('process_flow_property_annotations')->where('field_key', $field)->exists()) {
                throw new RuntimeException('Cannot discard entered flow parameters or sources.');
            }
        }
        DB::table('process_flow_property_fields')->whereIn('field_key', array_keys(self::FIELDS))->delete();
        Schema::table('process_flow_properties', fn (Blueprint $table) => $table->dropColumn(array_keys(self::FIELDS)));
    }
};
