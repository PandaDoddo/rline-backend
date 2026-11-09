<?php

use App\Modules\Catalogs\Domain\CatalogValues;
use App\Modules\ProcessFlows\Domain\FlowEnvironmentSchema;
use App\Modules\ProcessFlows\Infrastructure\FlowScreeningProjection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE process_flow_nodes,process_flow_properties,process_flow_property_annotations IN ACCESS EXCLUSIVE MODE');
        $baseline = json_decode(file_get_contents(resource_path('catalogs/flow-screening.json')), true, flags: JSON_THROW_ON_ERROR);
        if ($baseline['version'] !== 'flow-screening-v1') {
            throw new RuntimeException('Migration requires the immutable v1 screening baseline.');
        }
        $fields = array_column($baseline['fields'], null, 'id');
        Schema::create('process_flow_screening_items', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('kind', 16);
        });
        Schema::create('process_flow_screening_questions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->foreign('id')->references('id')->on('process_flow_screening_items');
            $table->smallInteger('bound_count')->nullable();
            $table->unique(['id', 'bound_count']);
        });
        $items = [];
        $questions = [];
        foreach ($fields as $key => $field) {
            $items[] = ['id' => $key, 'kind' => 'field'];
        }
        foreach ($baseline['mechanisms'] as $mechanism) {
            foreach ($mechanism['questions'] as $question) {
                $items[] = ['id' => $question['id'], 'kind' => 'question'];
                $condition = null;
                foreach ($question['conditions'] as $candidate) {
                    if ($candidate['operator'] !== 'yes') {
                        $condition = $candidate;
                        break;
                    }
                }
                $questions[] = ['id' => $question['id'], 'bound_count' => $condition === null ? null : ($condition['operator'] === 'in' ? 2 : 1)];
            }
        }
        DB::table('process_flow_screening_items')->insert($items);
        DB::table('process_flow_screening_questions')->insert($questions);
        Schema::create('process_flow_environments', function (Blueprint $table) {
            $table->uuid('flow_id')->primary();
            $table->foreign('flow_id')->references('id')->on('process_flow_nodes')->cascadeOnDelete();
            $table->unsignedBigInteger('updated_by');
            $table->timestampTz('updated_at', 6);
            foreach (FlowEnvironmentSchema::COLUMNS as [$column,$type]) {
                $table->{$type}($column)->nullable();
            }
        });
        foreach (FlowEnvironmentSchema::COLUMNS as $key => [$column,$type]) {
            if ($type === 'double') {
                DB::statement('ALTER TABLE process_flow_environments ADD CONSTRAINT flow_environment_'.$key.'_range CHECK ('.$column.' BETWEEN '.$fields[$key]['min'].' AND '.$fields[$key]['max'].')');
            }
        }
        Schema::create('process_flow_screening_comments', function (Blueprint $table) {
            $table->foreignUuid('flow_id')->constrained('process_flow_environments', 'flow_id')->cascadeOnDelete();
            $table->string('item_id', 64);
            $table->foreign('item_id')->references('id')->on('process_flow_screening_items');
            $table->text('comment');
            $table->primary(['flow_id', 'item_id']);
        });
        Schema::create('process_flow_screening_thresholds', function (Blueprint $table) {
            $table->foreignUuid('flow_id')->constrained('process_flow_environments', 'flow_id')->cascadeOnDelete();
            $table->string('question_id', 64);
            $table->smallInteger('bound_count');
            $table->double('bound_1');
            $table->double('bound_2')->nullable();
            $table->smallInteger('near_count');
            $table->double('near_1')->nullable();
            $table->double('near_2')->nullable();
            $table->primary(['flow_id', 'question_id']);
            $table->foreign(['question_id', 'bound_count'])->references(['id', 'bound_count'])->on('process_flow_screening_questions');
        });
        DB::statement('ALTER TABLE process_flow_screening_thresholds ADD CONSTRAINT flow_threshold_shape CHECK (bound_count IN (1,2) AND ((bound_count=1 AND bound_2 IS NULL) OR (bound_count=2 AND bound_2 IS NOT NULL AND bound_1<=bound_2)) AND near_count BETWEEN 0 AND bound_count AND ((near_count=0 AND near_1 IS NULL AND near_2 IS NULL) OR (near_count=1 AND near_1 IS NOT NULL AND near_2 IS NULL) OR (near_count=2 AND near_1 IS NOT NULL AND near_2 IS NOT NULL)))');
        DB::statement('ALTER TABLE process_flow_screening_thresholds ADD CONSTRAINT flow_threshold_finite CHECK (bound_1 BETWEEN -1e12 AND 1e12 AND (bound_2 IS NULL OR bound_2 BETWEEN -1e12 AND 1e12) AND (near_1 IS NULL OR near_1 BETWEEN 0 AND 1e12) AND (near_2 IS NULL OR near_2 BETWEEN 0 AND 1e12))');
        foreach (DB::table('process_flow_nodes')->whereNotNull('screening')->orderBy('id')->cursor() as $flow) {
            $state = json_decode($flow->screening, true, flags: JSON_THROW_ON_ERROR);
            if (array_diff(array_keys($state), ['values', 'comments', 'thresholds', 'updated_by', 'updated_at']) || array_diff(array_keys($state['values'] ?? []), array_keys($fields))) {
                throw new RuntimeException('Unknown screening data prevents normalization: '.$flow->id);
            }
            $row = ['flow_id' => $flow->id, 'updated_by' => $state['updated_by'], 'updated_at' => $state['updated_at']];
            foreach (FlowEnvironmentSchema::COLUMNS as $key => [$column,$type]) {
                $value = $state['values'][$key] ?? null;
                if ($value !== null && ($type === 'boolean' ? ! is_bool($value) : ! is_int($value) && ! is_float($value))) {
                    throw new RuntimeException('Invalid screening property: '.$key);
                }
                $row[$column] = $value;
            }
            if (array_key_exists('ph', $state['values'] ?? [])) {
                $ph = $state['values']['ph'];
                $card = DB::table('process_flow_cards')->where('flow_id', $flow->id)->first();
                $attributes = json_decode($card->attributes, true, flags: JSON_THROW_ON_ERROR);
                if (array_key_exists('ph', $attributes) && ! CatalogValues::same($attributes['ph'], $ph)) {
                    throw new RuntimeException('Conflicting physical and environmental pH requires an explicit resolution: '.$flow->id);
                }
                if (! array_key_exists('ph', $attributes)) {
                    DB::table('process_flow_properties')->updateOrInsert(['flow_id' => $flow->id], ['ph' => $ph]);
                    DB::table('process_flow_property_annotations')->updateOrInsert(['flow_id' => $flow->id, 'field_key' => 'ph'], ['value_present' => true, 'source_present' => true, 'citation' => 'Перенос из параметров среды (flow-screening-v1)', 'conditions' => null, 'source_by' => $state['updated_by'], 'source_at' => $state['updated_at'], 'verified_by' => null, 'verified_at' => null]);
                }
            }
            DB::table('process_flow_environments')->insert($row);
            foreach ($state['comments'] ?? [] as $key => $value) {
                DB::table('process_flow_screening_comments')->insert(['flow_id' => $flow->id, 'item_id' => $key, 'comment' => $value]);
            }
            foreach ($state['thresholds'] ?? [] as $key => $value) {
                DB::table('process_flow_screening_thresholds')->insert(['flow_id' => $flow->id, 'question_id' => $key, 'bound_count' => count($value['threshold']), 'bound_1' => $value['threshold'][0], 'bound_2' => $value['threshold'][1] ?? null, 'near_count' => count($value['near']), 'near_1' => $value['near'][0] ?? null, 'near_2' => $value['near'][1] ?? null]);
            }
        }
        DB::unprepared(FlowScreeningProjection::definition());
        foreach (DB::table('process_flow_nodes as f')->join('process_flow_screening_cards as p', 'p.flow_id', '=', 'f.id')->select(['f.id', 'f.screening', 'p.state'])->cursor() as $row) {
            $old = json_decode($row->screening, true, flags: JSON_THROW_ON_ERROR);
            $actual = json_decode($row->state, true, flags: JSON_THROW_ON_ERROR);
            // A formerly separate pH is now a compatibility alias of the canonical physical value.
            if (! array_key_exists('ph', $old['values'] ?? [])) {
                unset($actual['values']['ph']);
            }
            $old['updated_at'] = (new DateTimeImmutable($old['updated_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
            $actual['updated_at'] = (new DateTimeImmutable($actual['updated_at']))->format('Y-m-d\TH:i:s.uP');
            if ($old != $actual) {
                throw new RuntimeException('Screening data changed during normalization: '.$row->id);
            }
        }
        Schema::table('process_flow_nodes', fn (Blueprint $table) => $table->dropColumn('screening'));
    }

    public function down(): void
    {
        Schema::table('process_flow_nodes', fn (Blueprint $table) => $table->jsonb('screening')->nullable());
        DB::statement('UPDATE process_flow_nodes f SET screening=p.state FROM process_flow_screening_cards p WHERE p.flow_id=f.id');
        DB::statement('DROP VIEW process_flow_screening_cards');
        foreach (['process_flow_screening_thresholds', 'process_flow_screening_comments', 'process_flow_environments', 'process_flow_screening_questions', 'process_flow_screening_items'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
