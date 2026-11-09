<?php

namespace App\Providers;

use App\Documentation\ApiOperationTransformer;
use App\Documentation\DamageMechanismSchemaTransformer;
use App\Documentation\EquipmentSchemaTransformer;
use App\Documentation\FacilitySchemaTransformer;
use App\Documentation\FortifySchemaTransformer;
use App\Documentation\InstallationCostSchemaTransformer;
use App\Documentation\InstallationMaintenanceSchemaTransformer;
use App\Documentation\MaterialCostSchemaTransformer;
use App\Documentation\ProcessFlowSchemaTransformer;
use App\Documentation\SteelGradeSchemaTransformer;
use App\Documentation\SubstanceSchemaTransformer;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\ServiceProvider;

class ApiDocumentationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Scramble::configure()->withOperationTransformers(ApiOperationTransformer::class)
            ->withDocumentTransformers([FortifySchemaTransformer::class, FacilitySchemaTransformer::class, EquipmentSchemaTransformer::class, SubstanceSchemaTransformer::class, SteelGradeSchemaTransformer::class, DamageMechanismSchemaTransformer::class, MaterialCostSchemaTransformer::class, InstallationCostSchemaTransformer::class, InstallationMaintenanceSchemaTransformer::class, ProcessFlowSchemaTransformer::class]);
    }
}
