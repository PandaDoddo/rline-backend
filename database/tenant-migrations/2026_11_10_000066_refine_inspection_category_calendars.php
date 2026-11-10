<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared($this->definition(true));
    }

    public function down(): void
    {
        if (DB::table('equipment_inspection_category_stages as s')->join('equipment_inspections as i','i.id','=','s.inspection_id')->where('s.group_code','cl')->where('i.kind','НО')->exists()) {
            throw new RuntimeException('Cannot remove support for saved external chloride visual inspections.');
        }
        DB::unprepared($this->definition(false));
    }

    private function definition(bool $refined): string
    {
        $invalidKind = $refined
            ? "(s.group_code='thin' AND i.kind NOT IN ('ТО-1','ТО-2','ЭПБ','УЗТ')) OR (s.group_code IN ('ext','cui','cl') AND i.kind NOT IN ('ТО-1','ТО-2','ЭПБ','НО')) OR (s.group_code IN ('scc','hic','aus') AND i.kind NOT IN ('ТО-1','ТО-2','ЭПБ'))"
            : "i.kind NOT IN ('ТО-1','ТО-2','ЭПБ','НО','УЗТ') OR i.kind='НО' AND s.group_code NOT IN ('ext','cui')";
        return <<<SQL
CREATE OR REPLACE FUNCTION guard_inspection_category_calendar() RETURNS trigger LANGUAGE plpgsql AS \$\$
DECLARE owner_id uuid:=COALESCE(NEW.equipment_id,OLD.equipment_id);
BEGIN
 IF EXISTS(SELECT 1 FROM equipment_inspection_category_stages s JOIN equipment_inspections i ON i.id=s.inspection_id
   WHERE s.equipment_id=owner_id AND (i.performed_on IS NULL OR ($invalidKind))) THEN
  RAISE EXCEPTION 'A category stage requires an applicable performed inspection' USING ERRCODE='23514';
 END IF;
 IF EXISTS(SELECT s.group_code,coalesce(i.performed_on,s.reference_date) FROM equipment_inspection_category_stages s LEFT JOIN equipment_inspections i ON i.id=s.inspection_id
   WHERE s.equipment_id=owner_id GROUP BY s.group_code,coalesce(i.performed_on,s.reference_date) HAVING count(*)>1) THEN
  RAISE EXCEPTION 'Two category stages cannot share a group and date' USING ERRCODE='23514';
 END IF;
 RETURN NULL;
END; \$\$;
SQL;
    }
};
