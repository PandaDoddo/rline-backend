<?php

namespace App\Http\Requests;

use App\Domain\EquipmentDiagnostics;
use App\Domain\EquipmentFlowContacts;
use App\Domain\EquipmentStructure;
use App\Domain\EquipmentThickness;

class UpdateEquipmentRequest extends EquipmentRequest
{
    public function rules(): array
    {
        $rules = array_replace(parent::rules(), ['profile_code' => 'prohibited', 'version' => 'required|integer|min:1',
            'status' => 'sometimes|in:active,archived', 'verification' => 'sometimes|array:'.implode(',', array_keys(config('equipment.passport')))]);
        foreach (array_keys(config('equipment.passport')) as $key) {
            $rules['verification.'.$key] = 'sometimes|boolean';
        }

        return $rules + EquipmentStructure::rules() + EquipmentDiagnostics::rules() + EquipmentThickness::rules() + EquipmentFlowContacts::rules();
    }
}
