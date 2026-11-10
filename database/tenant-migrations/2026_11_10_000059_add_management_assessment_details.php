<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_management_details', function (Blueprint $table) {
            $table->foreignUuid('installation_id')->primary()->constrained('installations')->cascadeOnDelete();
            $table->date('assessed_on')->nullable();
            $table->string('auditor_name', 255)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE installation_management_details ADD CONSTRAINT management_auditor_not_blank CHECK (length(btrim(auditor_name))>0), ADD CONSTRAINT management_notes_length CHECK (length(notes)<=4000)");
        // Existing answers do not establish an assessment date or auditor.
        DB::statement('INSERT INTO installation_management_details (installation_id,created_at,updated_at) SELECT DISTINCT installation_id,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP FROM installation_management_answers');
        DB::statement('ALTER TABLE installation_management_answers ADD CONSTRAINT management_answer_assessment_owner FOREIGN KEY (installation_id) REFERENCES installation_management_details(installation_id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED');
    }

    public function down(): void
    {
        if (DB::table('installation_management_details')->whereNotNull('assessed_on')->orWhereNotNull('auditor_name')->orWhereNotNull('notes')->exists()) {
            throw new RuntimeException('Cannot discard entered management assessment details.');
        }
        DB::statement('ALTER TABLE installation_management_answers DROP CONSTRAINT management_answer_assessment_owner');
        Schema::drop('installation_management_details');
    }
};
