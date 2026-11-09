<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_component_unresolved_targets', function (Blueprint $table) {
            $table->uuid('connection_id');
            $table->uuid('equipment_id');
            $table->string('source_reference', 255);
            $table->unsignedInteger('position');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['connection_id', 'source_reference']);
            $table->foreign(['connection_id', 'equipment_id'], 'unresolved_target_owner_fk')->references(['id', 'equipment_id'])->on('equipment_component_connections')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE equipment_component_unresolved_targets ADD CHECK (length(btrim(source_reference))>0)');
        $this->projection(true);
    }

    private function projection(bool $includeUnresolved): void
    {
        $property = $includeUnresolved ? ",'unresolved_targets',COALESCE(u.ids,'[]'::jsonb)" : '';
        $complete = $includeUnresolved ? ' AND COALESCE(jsonb_array_length(u.ids),0)=0' : '';
        $join = $includeUnresolved ? 'LEFT JOIN LATERAL (SELECT jsonb_agg(source_reference ORDER BY position,source_reference) ids FROM equipment_component_unresolved_targets WHERE connection_id=c.id) u ON TRUE' : '';
        DB::statement(<<<SQL
CREATE OR REPLACE VIEW equipment_component_graph_cards AS
SELECT s.*,COALESCE(g.items,'[]'::jsonb) connections,COALESCE(h.count,0) history_count
FROM equipment_component_specifications s
LEFT JOIN LATERAL (
 SELECT jsonb_agg(jsonb_build_object(
  'id',c.id,'status',c.status,'position',c.position,'side',c.side,'joining_method',c.joining_method,
  'collar_present',c.collar_present,'thread_kind',c.thread_kind,'thread_size',c.thread_size,
  'notes',c.notes,'source_reference',c.source_reference,'targets',COALESCE(t.ids,'[]'::jsonb),
  'complete',COALESCE(jsonb_array_length(t.ids)>0,FALSE) AND c.joining_method IS NOT NULL$complete$property
 ) ORDER BY c.position,c.id) items
 FROM equipment_component_connections c
 LEFT JOIN LATERAL (SELECT jsonb_agg(target_component_id ORDER BY position,target_component_id) ids FROM equipment_component_connection_targets WHERE connection_id=c.id) t ON TRUE
 $join
 WHERE c.component_id=s.component_id
) g ON TRUE
LEFT JOIN LATERAL (SELECT count(*) count FROM equipment_component_history WHERE component_id=s.component_id) h ON TRUE
SQL);
    }

    public function down(): void
    {
        if (DB::table('equipment_component_unresolved_targets')->exists()) {
            throw new RuntimeException('Cannot discard unresolved component references.');
        }
        $this->projection(false);
        Schema::dropIfExists('equipment_component_unresolved_targets');
    }
};
