<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE equipment ADD CONSTRAINT equipment_installation_owner_unique UNIQUE(id,installation_id)');
        DB::statement('ALTER TABLE process_flow_nodes ADD CONSTRAINT process_flow_installation_owner_unique UNIQUE(id,installation_id)');
        Schema::create('equipment_flow_selections', function (Blueprint $table) {
            $table->uuid('equipment_id');
            $table->uuid('flow_id');
            $table->uuid('installation_id');
            $table->unsignedInteger('position');
            $table->unsignedBigInteger('created_by');
            $table->timestampsTz(6);
            $table->primary(['equipment_id', 'flow_id']);
        });
        DB::statement('ALTER TABLE equipment_flow_selections ADD CONSTRAINT selected_equipment_owner_fk FOREIGN KEY(equipment_id,installation_id) REFERENCES equipment(id,installation_id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE equipment_flow_selections ADD CONSTRAINT selected_flow_owner_fk FOREIGN KEY(flow_id,installation_id) REFERENCES process_flow_nodes(id,installation_id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE equipment_flow_selections ADD CONSTRAINT selected_flow_position_unique UNIQUE(equipment_id,position) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE equipment_flow_selections ADD CHECK(position>=0)');
        DB::statement(<<<'SQL'
INSERT INTO equipment_flow_selections(equipment_id,flow_id,installation_id,position,created_by,created_at,updated_at)
SELECT x.equipment_id,x.flow_id,e.installation_id,(row_number() OVER(PARTITION BY x.equipment_id ORDER BY x.flow_id))-1,e.updated_by,now(),now()
FROM (SELECT DISTINCT c.equipment_id,l.flow_id FROM equipment_flow_contacts c JOIN equipment_flow_links l ON l.contact_id=c.id) x
JOIN equipment e ON e.id=x.equipment_id
SQL);
    }

    public function down(): void
    {
        if (DB::table('equipment_flow_selections')->exists()) {
            throw new RuntimeException('Cannot discard selected equipment flows.');
        }
        Schema::drop('equipment_flow_selections');
        DB::statement('ALTER TABLE process_flow_nodes DROP CONSTRAINT process_flow_installation_owner_unique');
        DB::statement('ALTER TABLE equipment DROP CONSTRAINT equipment_installation_owner_unique');
    }
};
