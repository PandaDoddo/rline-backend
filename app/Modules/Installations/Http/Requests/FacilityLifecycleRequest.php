<?php

namespace App\Modules\Installations\Http\Requests;

use App\Http\Requests\TenantWriteRequest;

class FacilityLifecycleRequest extends TenantWriteRequest
{
    public function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'action' => ($this->isMethod('post') ? 'required' : 'sometimes').'|in:archive,restore', 'cascade' => 'sometimes|boolean'];
    }
}
