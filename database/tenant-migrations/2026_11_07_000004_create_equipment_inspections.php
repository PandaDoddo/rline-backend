<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_inspections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->string('kind', 30);
            $table->boolean('is_primary')->default(false);
            $table->date('planned_on')->nullable();
            $table->date('performed_on')->nullable();
            $table->string('report_number', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['equipment_id', 'performed_on']);
        });
        DB::statement('CREATE UNIQUE INDEX equipment_primary_inspection_unique ON equipment_inspections (equipment_id) WHERE is_primary');
        $profiles = array_keys(array_filter(config('equipment.profiles'), fn ($profile) => $profile['g'] === 'ves'));
        DB::table('equipment')->whereIn('profile_code', $profiles)->orderBy('id')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                $passport = json_decode($row->passport, true);
                $rp = $passport['registration_authority'] ?? config('equipment.profiles')[$row->profile_code]['rp'];
                DB::table('equipment_inspections')->insert(['id' => (string) Str::uuid(), 'equipment_id' => $row->id, 'kind' => $rp === 'РТН' ? 'ТО-2' : 'ТО-1', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_inspections');
    }
};
