<?php

namespace App\Http\Requests;

class UpdateFacilityNodeRequest extends FacilityNodeRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), ['kind' => 'prohibited', 'version' => 'required|integer|min:1']);
    }
}
