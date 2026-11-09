<?php

namespace App\Modules\Catalogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DamageMechanismIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['q' => 'sometimes|nullable|string|max:255', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'status' => 'sometimes|in:active,archived', 'mode' => 'sometimes|in:571,581,corrosion,cracking,fatigue,embrittlement', 'materials' => 'sometimes|array|max:11', 'materials.*' => ['required', 'string', 'distinct', Rule::in(array_keys(config('damage-mechanisms.groups')))]];
    }
}
