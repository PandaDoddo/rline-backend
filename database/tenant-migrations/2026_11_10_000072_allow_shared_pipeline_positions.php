<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Distinct passport records may describe different sections of the same line.
        DB::statement('DROP INDEX equipment_installation_tag_unique');
        DB::statement("CREATE UNIQUE INDEX equipment_installation_tag_unique ON equipment (installation_id,tag) WHERE tag IS NOT NULL AND profile_code NOT IN ('pipe_tech','pipe_steam','pipe_gas')");
    }

    public function down(): void
    {
        if (DB::select('SELECT 1 FROM equipment WHERE tag IS NOT NULL GROUP BY installation_id,tag HAVING count(*)>1 LIMIT 1')) {
            throw new RuntimeException('Cannot restore unique positions while distinct pipeline sections share a position.');
        }
        DB::statement('DROP INDEX equipment_installation_tag_unique');
        DB::statement('CREATE UNIQUE INDEX equipment_installation_tag_unique ON equipment (installation_id,tag) WHERE tag IS NOT NULL');
    }
};
