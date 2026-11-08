<?php

namespace App\Http\Requests;

class UpdateInstallationRequest extends InstallationRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), ['version' => 'required|integer|min:1']);
    }
}
