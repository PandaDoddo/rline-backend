<?php

namespace App\Modules\Rbi\Http\Requests;

use App\Http\Requests\TenantWriteRequest;

class UpdateManagementRequest extends TenantWriteRequest
{
    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'schema_version' => 'required|string|max:100', 'answers' => 'required|array|max:200',
            'answers.*' => 'required|array:question_id,state,value,comment', 'answers.*.question_id' => 'required|string|max:32|distinct:strict',
            'answers.*.state' => 'required|in:answered,unanswered,not_applicable',
            /** @var bool|float|array<int,int>|null */
            'answers.*.value' => 'present|nullable',
            'answers.*.comment' => 'present|nullable|string|max:4000'];
    }
}
