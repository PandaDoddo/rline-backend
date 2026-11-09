<?php

use App\Modules\Diagnostics\Infrastructure\DiagnosticPlanProjection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(DiagnosticPlanProjection::definition(config('equipment.profiles')));
        DB::statement('ALTER VIEW equipment_diagnostic_plans RENAME TO equipment_diagnostic_plans_v1');
        // Keep the equipment predicate outside the function: a paginated read computes
        // only its equipment IDs, while full filters/sorts remain database operations.
        DB::statement('CREATE VIEW equipment_diagnostic_plans AS SELECT e.id equipment_id,p.* FROM equipment e CROSS JOIN LATERAL equipment_diagnostic_plan(e.id) p');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW equipment_diagnostic_plans');
        DB::statement('DROP FUNCTION equipment_diagnostic_plan(uuid)');
        DB::statement('ALTER VIEW equipment_diagnostic_plans_v1 RENAME TO equipment_diagnostic_plans');
    }
};
