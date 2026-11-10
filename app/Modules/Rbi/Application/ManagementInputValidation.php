<?php

namespace App\Modules\Rbi\Application;

use Illuminate\Support\Facades\Validator;

final class ManagementInputValidation
{
    public function validated(array $input): array
    {
        return Validator::make($this->normalize($input), $this->rules())->validate();
    }

    public function normalize(array $input): array
    {
        return $this->trim($input);
    }

    private function trim(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->trim(...), $value);
        }

        return is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'schema_version' => 'required|string|max:100', 'answers' => 'present|array|list|max:200',
            'answers.*' => 'required|array:question_id,state,value,comment', 'answers.*.question_id' => 'required|string|max:32|distinct:strict',
            'answers.*.state' => 'required|in:answered,unanswered,not_applicable',
            /** @var bool|float|array<int,int>|null */
            'answers.*.value' => 'present|nullable', 'answers.*.comment' => 'present|nullable|string|max:4000',
            'details' => 'sometimes|array:assessed_on,auditor_name,notes', 'details.assessed_on' => 'sometimes|nullable|date_format:Y-m-d',
            'details.auditor_name' => 'sometimes|nullable|string|max:255', 'details.notes' => 'sometimes|nullable|string|max:4000'];
    }
}
