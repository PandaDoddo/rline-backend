<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SteelGradeIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['q' => 'sometimes|nullable|string|max:200', 'status' => 'sometimes|in:active,archived', 'origin' => 'sometimes|in:base,custom', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
