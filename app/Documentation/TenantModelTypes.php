<?php

namespace App\Documentation;

use App\Models\FacilityNode;
use App\Models\Installation;
use App\Models\TenantModel;
use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\Event\PropertyFetchEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Infer\Extensions\PropertyTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\FloatType;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\MixedType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Dedoc\Scramble\Support\Type\UnknownType;
use Illuminate\Support\Carbon;

/** Describe tenant attributes without opening an organization's database during documentation generation. */
class TenantModelTypes implements MethodReturnTypeExtension, PropertyTypeExtension
{
    public function shouldHandle(ObjectType $type): bool
    {
        return $type->isInstanceOf(TenantModel::class);
    }

    public function getPropertyType(PropertyFetchEvent $event): ?Type
    {
        return match ($event->name) {
            'version', 'created_by', 'updated_by', 'position' => new IntegerType,
            'material', 'notes', 'report_number' => Union::wrap([new StringType, new NullType]),
            'planned_on', 'performed_on' => Union::wrap([new ObjectType(Carbon::class), new NullType]),
            'diameter_mm', 'length_mm', 'nominal_thickness_mm', 'rejection_passport_mm', 'rejection_epb_mm' => Union::wrap([new FloatType, new NullType]),
            'id', 'name', 'kind', 'installation_id', 'profile_code', 'status' => new StringType,
            'code', 'parent_id' => Union::wrap([new StringType, new NullType]),
            'passport', 'attributes', 'checks' => new ArrayType(new MixedType, new StringType),
            'installation' => new ObjectType(Installation::class),
            'parent' => Union::wrap([new ObjectType(FacilityNode::class), new NullType]),
            'created_at', 'updated_at' => new ObjectType(Carbon::class),
            default => new UnknownType('Undocumented tenant attribute: '.$event->name),
        };
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        return in_array($event->name, ['toArray', 'attributesToArray', 'getAttributes', 'only', 'except'], true)
            ? new ArrayType(new MixedType, new StringType) : null;
    }
}
