<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InstallationMaintenanceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['kind' => 'required|in:mri,repair,stop', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
