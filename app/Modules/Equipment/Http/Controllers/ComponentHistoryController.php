<?php

namespace App\Modules\Equipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Equipment\Http\Resources\ComponentHistoryResource;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Equipment\Models\EquipmentComponent;

class ComponentHistoryController extends Controller
{
    public function index(string $equipment, string $component)
    {
        Equipment::without('passportProjection')->findOrFail($equipment);
        $model = EquipmentComponent::where('equipment_id', $equipment)->findOrFail($component);

        return ComponentHistoryResource::collection($model->historyEntries()->orderByRaw('occurred_on DESC NULLS LAST')->orderByDesc('id')->paginate(30));
    }
}
