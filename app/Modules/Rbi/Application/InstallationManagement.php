<?php

namespace App\Modules\Rbi\Application;

use App\Modules\Installations\Models\Installation;
use App\Modules\Rbi\Domain\ManagementAssessment;
use App\Modules\Rbi\Models\ManagementAnswer;
use App\Modules\Shared\Infrastructure\TenantWrites;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class InstallationManagement
{
    public function schema(): array
    {
        static $schema;

        return $schema ??= json_decode(file_get_contents(resource_path('rbi/management-questionnaire-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function read(Installation $installation): array
    {
        $questions = [];
        foreach ($this->schema()['sections'] as $section) {
            foreach ($section['questions'] as $question) {
                $questions[$question['id']] = $question;
            }
        }
        $answers = ManagementAnswer::where('installation_id', $installation->id)->with('selections')->orderBy('question_id')->get()->map(function ($answer) use ($questions) {
            $kind = $questions[$answer->question_id]['kind'];
            $value = $answer->state !== 'answered' ? null : match ($kind) {
                'boolean' => $answer->boolean_value,'score' => $answer->score_value,default => $answer->selections->pluck('option_index')->all()
            };

            return ['question_id' => $answer->question_id, 'state' => $answer->state, 'value' => $value, 'comment' => $answer->comment];
        })->all();

        return ['installation_id' => (string) $installation->id, 'version' => (int) $installation->version, 'schema_version' => $this->schema()['version'], 'answers' => $answers, 'assessment' => (new ManagementAssessment)->calculate($this->schema()['sections'], $answers)];
    }

    public function update(string $id, array $data, int $actor, TenantWrites $writes): array
    {
        return $writes->transaction(function () use ($id, $data, $actor, $writes) {
            $installation = Installation::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($installation->status === 'active', 422, 'Сначала восстановите установку из архива.');
            $writes->version($installation, $data['version']);
            abort_unless($data['schema_version'] === $this->schema()['version'], 409, 'Версия анкеты изменилась. Обновите данные.');
            $before = $this->read($installation);
            $merged = array_column($before['answers'], null, 'question_id');
            foreach ($data['answers'] as $answer) {
                $merged[$answer['question_id']] = $answer;
            }
            try {
                (new ManagementAssessment)->calculate($this->schema()['sections'], array_values($merged));
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['answers' => $exception->getMessage()]);
            }
            $questions = [];
            foreach ($this->schema()['sections'] as $section) {
                foreach ($section['questions'] as $question) {
                    $questions[$question['id']] = $question;
                }
            }
            foreach ($data['answers'] as $answer) {
                $model = ManagementAnswer::where('installation_id', $id)->where('question_id', $answer['question_id'])->first() ?? (new ManagementAnswer)->forceFill(['installation_id' => $id, 'question_id' => $answer['question_id']]);
                $kind = $questions[$answer['question_id']]['kind'];
                $value = $answer['state'] === 'answered' ? ($answer['value'] ?? null) : null;
                $model->fill(['state' => $answer['state'], 'comment' => $answer['comment'] ?? null, 'boolean_value' => $kind === 'boolean' ? $value : null, 'score_value' => $kind === 'score' ? $value : null, 'updated_by' => $actor])->save();
                DB::connection('tenant')->table('installation_management_selections')->where('answer_id', $model->id)->delete();
                if ($kind === 'multiple' && is_array($value) && $value) {
                    DB::connection('tenant')->table('installation_management_selections')->insert(array_map(fn ($option) => ['answer_id' => $model->id, 'question_id' => $model->question_id, 'option_index' => $option], $value));
                }
            }
            $installation->forceFill(['version' => $installation->version + 1, 'updated_by' => $actor])->save();
            $after = $this->read($installation);
            $writes->event('installation_management', $installation, 'updated', $actor, $before, $after);

            return $after;
        });
    }
}
