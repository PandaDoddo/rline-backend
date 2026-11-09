<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_inspections', function (Blueprint $table) {
            $table->date('next_on')->nullable();
            $table->string('organization', 1000)->nullable();
            $table->string('inspection_type', 100)->nullable();
            $table->jsonb('follow_up_dates')->default('{}');
            $table->jsonb('unknown_fields')->default('[]');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
        });
        // A single projection for reads, SQL filters and ordering. No registry seed dates.
        DB::statement(<<<'SQL'
CREATE VIEW equipment_diagnostic_plans AS
WITH facts AS (
    SELECT equipment_id, kind, MAX(performed_on) performed_on
    FROM equipment_inspections GROUP BY equipment_id, kind
), issued AS (
    SELECT id, equipment_id, kind, performed_on issued_on, COALESCE(next_on, planned_on) planned_on
    FROM equipment_inspections WHERE performed_on IS NOT NULL
    UNION ALL
    SELECT i.id, i.equipment_id, j.key kind, i.performed_on issued_on, j.value::date planned_on
    FROM equipment_inspections i CROSS JOIN LATERAL jsonb_each_text(CASE WHEN jsonb_typeof(i.follow_up_dates)='object' THEN i.follow_up_dates ELSE '{}'::jsonb END) j
    WHERE i.performed_on IS NOT NULL
), ranked AS (
    SELECT i.*, ROW_NUMBER() OVER (PARTITION BY i.equipment_id, i.kind ORDER BY i.issued_on DESC, i.planned_on NULLS LAST, i.id) position
    FROM issued i LEFT JOIN facts f ON f.equipment_id=i.equipment_id AND f.kind=i.kind
    WHERE f.performed_on IS NULL OR i.issued_on>=f.performed_on
), pending AS (
    SELECT equipment_id, kind, planned_on FROM equipment_inspections WHERE performed_on IS NULL AND planned_on IS NOT NULL
    UNION ALL
    SELECT equipment_id, kind, planned_on FROM ranked WHERE position=1 AND planned_on IS NOT NULL
), plans AS (
    SELECT equipment_id, kind, MIN(planned_on) planned_on FROM pending GROUP BY equipment_id,kind
), basis AS (
    SELECT COALESCE(f.equipment_id,p.equipment_id) equipment_id, COALESCE(f.kind,p.kind) kind, f.performed_on,p.planned_on
    FROM facts f FULL JOIN plans p ON f.equipment_id=p.equipment_id AND f.kind=p.kind
)
SELECT b.*, a.kind absorbed_by, a.planned_on absorbed_on
FROM basis b LEFT JOIN LATERAL (
    SELECT other.kind,other.planned_on FROM basis other
    WHERE other.equipment_id=b.equipment_id AND b.planned_on IS NOT NULL AND other.planned_on IS NOT NULL
    AND ((b.kind='ТО-1' AND other.kind IN ('ТО-2','ЭПБ')) OR (b.kind='ТО-2' AND other.kind='ЭПБ'))
    AND (EXTRACT(YEAR FROM b.planned_on)=EXTRACT(YEAR FROM other.planned_on) OR ABS(b.planned_on-other.planned_on)<=30)
    ORDER BY CASE other.kind WHEN 'ЭПБ' THEN 0 ELSE 1 END,other.planned_on LIMIT 1
) a ON TRUE
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS equipment_diagnostic_plans');
        Schema::table('equipment_inspections', fn (Blueprint $table) => $table->dropColumn(['next_on', 'organization', 'inspection_type', 'follow_up_dates', 'unknown_fields', 'verified_by', 'verified_at']));
    }
};
