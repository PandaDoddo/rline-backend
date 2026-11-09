<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbi_management_sections', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->text('title');
            $table->double('weight');
            $table->integer('position');
        });
        Schema::create('rbi_management_questions', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('section_id', 32);
            $table->foreign('section_id')->references('id')->on('rbi_management_sections');
            $table->string('number', 32);
            $table->string('subnumber', 32);
            $table->text('text');
            $table->text('guidance')->nullable();
            $table->string('kind', 16);
            $table->double('maximum');
            $table->integer('position');
        });
        Schema::create('rbi_management_options', function (Blueprint $table) {
            $table->string('question_id', 32);
            $table->integer('option_index');
            $table->primary(['question_id', 'option_index']);
            $table->foreign('question_id')->references('id')->on('rbi_management_questions');
            $table->text('label');
            $table->double('points');
            $table->boolean('exclusive');
        });
        Schema::create('installation_management_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('installation_id')->constrained()->cascadeOnDelete();
            $table->string('question_id', 32);
            $table->unique(['installation_id', 'question_id']);
            $table->unique(['id', 'question_id']);
            $table->foreign('question_id')->references('id')->on('rbi_management_questions');
            $table->string('state', 20);
            $table->boolean('boolean_value')->nullable();
            $table->double('score_value')->nullable();
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz();
        });
        Schema::create('installation_management_selections', function (Blueprint $table) {
            $table->uuid('answer_id');
            $table->string('question_id', 32);
            $table->integer('option_index');
            $table->primary(['answer_id', 'option_index']);
            $table->foreign(['answer_id', 'question_id'])->references(['id', 'question_id'])->on('installation_management_answers')->cascadeOnDelete();
            $table->foreign(['question_id', 'option_index'])->references(['question_id', 'option_index'])->on('rbi_management_options');
        });
        DB::statement("ALTER TABLE installation_management_answers ADD CONSTRAINT management_answer_state CHECK (state IN ('answered','unanswered','not_applicable'))");
        DB::statement("ALTER TABLE installation_management_answers ADD CONSTRAINT management_answer_values CHECK ((boolean_value IS NULL OR score_value IS NULL) AND (state='answered' OR (boolean_value IS NULL AND score_value IS NULL)) AND (state<>'not_applicable' OR length(trim(coalesce(comment,'')))>0))");
        DB::statement("ALTER TABLE installation_management_answers ADD CONSTRAINT management_answer_finite_score CHECK (score_value IS NULL OR (score_value>=0 AND score_value<'Infinity'::float8))");
        $schema = json_decode(file_get_contents(resource_path('rbi/management-questionnaire-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $sections = [];
        $questions = [];
        $options = [];
        foreach ($schema['sections'] as $section) {
            $sections[] = array_diff_key($section, ['questions' => true]);
            foreach ($section['questions'] as $question) {
                $questions[] = array_diff_key($question, ['options' => true]) + ['section_id' => $section['id']];
                foreach ($question['options'] as $option) {
                    $option['option_index'] = $option['index'];
                    unset($option['index']);
                    $options[] = ['question_id' => $question['id']] + $option;
                }
            }
        }
        DB::table('rbi_management_sections')->insert($sections);
        DB::table('rbi_management_questions')->insert($questions);
        DB::table('rbi_management_options')->insert($options);
    }

    public function down(): void
    {
        foreach (['installation_management_selections', 'installation_management_answers', 'rbi_management_options', 'rbi_management_questions', 'rbi_management_sections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
