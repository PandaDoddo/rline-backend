<?php

namespace App\Modules\Equipment\Http\Requests;

use App\Modules\Equipment\Application\EquipmentInputValidation;

class UpdateEquipmentRequest extends EquipmentRequest
{
    public function rules(): array
    {
        return app(EquipmentInputValidation::class)->rules(true);
    }
}
