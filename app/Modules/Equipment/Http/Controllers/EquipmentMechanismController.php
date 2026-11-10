<?php

namespace App\Modules\Equipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Equipment\Application\ComponentSpecifications;
use App\Modules\Equipment\Application\EquipmentMechanisms;
use App\Modules\Equipment\Http\Resources\MechanismElementResource;
use App\Modules\Equipment\Models\Equipment;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;

class EquipmentMechanismController extends Controller
{
    #[Response(status: 200, type: MechanismElementResource::class)]
    public function show(string $equipment, string $component, EquipmentMechanisms $mechanisms)
    {
        return DB::connection('tenant')->transaction(function () use ($equipment, $component, $mechanisms) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $record = Equipment::with([...ComponentSpecifications::eagerLoads(), 'inspections'])->findOrFail($equipment);

            return (new MechanismElementResource($mechanisms->detail($record, $component)))->response();
        });
    }
}
