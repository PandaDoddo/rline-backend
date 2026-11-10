<?php

namespace App\Documentation;

use App\Modules\Diagnostics\Domain\InspectionCategorySchema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class InspectionCategoryApiSchema
{
    public static function make(bool $input = false): ObjectType
    {
        $uuid = fn () => (new StringType)->format('uuid');
        $observation = (new ObjectType)->addProperty('component_id', $uuid())
            ->addProperty('category', (new StringType)->enum(InspectionCategorySchema::metadata()['categories'])->nullable(true))
            ->addProperty('cracks_present', (new BooleanType)->nullable(true));
        $observation->setRequired($input ? ['component_id'] : array_keys($observation->properties));
        $stage = (new ObjectType)->addProperty('id', $uuid())->addProperty('group_code', (new StringType)->enum(array_keys(InspectionCategorySchema::groups())))
            ->addProperty('inspection_id', $uuid()->nullable(true))->addProperty('reference_date', (new StringType)->format('date')->nullable(true))
            ->addProperty('observations', (new ArrayType)->setItems($observation));
        if ($input) {
            $stage->addProperty('reset_components', (new ArrayType)->setItems($uuid()))->setRequired(['id', 'group_code']);
        } else {
            $stage->addProperty('source_date', (new StringType)->format('date'))
                ->addProperty('diagnostic_kind', (new StringType)->enum(['ТО-1', 'ТО-2', 'ЭПБ', 'НО', 'УЗТ'])->nullable(true))
                ->addProperty('origin', (new StringType)->enum(['inspection', 'manual']))->addProperty('persisted', new BooleanType);
            $stage->setRequired(array_keys($stage->properties));
            $stage->addProperty('automatic_category', (new StringType)->enum(['A', 'B', 'C', 'D', 'E'])->nullable(true));
            $automatic = (new ObjectType)->addProperty('component_id', $uuid())->addProperty('category', (new StringType)->enum(['A', 'B', 'C', 'D', 'E'])->nullable(true))->addProperty('measured_cmls', (new IntegerType)->nullable(true));
            $automatic->setRequired(array_keys($automatic->properties));
            $stage->addProperty('automatic_observations', (new ArrayType)->setItems($automatic));
            $stage->setRequired(array_keys($stage->properties));
        }
        $result = (new ObjectType)->addProperty('schema_version', new StringType)->addProperty('stages', (new ArrayType)->setItems($stage));
        if ($input) {
            $result->addProperty('delete_stage_ids', (new ArrayType)->setItems($uuid()));
        }
        $result->setRequired($input ? ['schema_version'] : ['schema_version', 'stages']);
        if (! $input) {
            $groups = new ObjectType;
            foreach (InspectionCategorySchema::groups() as $group => $_) {
                $counts = new ObjectType;
                foreach (['A', 'B', 'C', 'D'] as $category) {
                    $counts->addProperty($category, new IntegerType);
                }
                $counts->setRequired(['A', 'B', 'C', 'D']);
                $status = (new ObjectType)->addProperty('applicability', (new StringType)->enum(['active', 'inactive', 'unknown']))->addProperty('counts', $counts)->setRequired(['applicability', 'counts']);
                $groups->addProperty($group, $status);
            }
            $groups->setRequired(array_keys($groups->properties));
            $element = (new ObjectType)->addProperty('component_id', $uuid())->addProperty('scope_id', new StringType)->addProperty('active', new BooleanType)
                ->addProperty('insulated', (new BooleanType)->nullable(true))->addProperty('cml_count', new IntegerType)->addProperty('groups', $groups);
            $element->setRequired(array_keys($element->properties));
            $result->addProperty('elements', (new ArrayType)->setItems($element))->addProperty('mechanisms_ready', new BooleanType)->setRequired(['schema_version', 'stages', 'elements', 'mechanisms_ready']);
        }

        return $result;
    }
}
