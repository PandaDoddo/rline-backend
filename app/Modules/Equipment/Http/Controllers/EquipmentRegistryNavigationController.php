<?php

namespace App\Modules\Equipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Equipment\Application\EquipmentRegistryNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EquipmentRegistryNavigationController extends Controller
{
    /** @return array{data: array{total: int, groups: list<array{code: string, count: int}>, locations: list<array{id: string, parent_id: string|null, kind: string, name: string, status: string, count: int}>}} */
    public function __invoke(Request $request, EquipmentRegistryNavigation $navigation)
    {
        $filters = $request->validate(['q' => 'sometimes|nullable|string|max:255', 'status' => 'sometimes|in:active,archived']);

        return DB::connection('tenant')->transaction(function () use ($filters, $navigation) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return ['data' => $navigation->read($filters)];
        });
    }
}
