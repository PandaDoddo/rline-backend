<?php

namespace App\Documentation;

use App\Modules\Diagnostics\Domain\VisualInspectionSchema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class VisualInspectionApiSchema
{
    private static function object(array $properties, bool $required = true): ObjectType
    {
        $type = new ObjectType;
        foreach ($properties as $key => $property) {
            $type->addProperty($key, $property);
        }
        if ($required) {
            $type->setRequired(array_keys($properties));
        }

        return $type;
    }

    private static function list($type): ArrayType
    {
        return (new ArrayType)->setItems($type);
    }

    public static function make(bool $input = false): ObjectType
    {
        $uuid = fn () => (new StringType)->format('uuid');
        $date = fn () => (new StringType)->format('date')->nullable(true);
        $values = new ObjectType;
        foreach (VisualInspectionSchema::fields() as $key => $definition) {
            $type = match ($definition['type']) {
                'boolean' => new BooleanType,
                'number' => new NumberType,
                default => new StringType,
            };
            if ($type instanceof NumberType) {
                $type->setMin($definition['min'])->setMax($definition['max']);
            }
            if (isset($definition['options'])) {
                $type->enum(array_keys($definition['options']));
            }
            $values->addProperty($key, $type->nullable(true)->setDescription($definition['label']));
        }
        $anomaly = self::object(['stage_id' => $uuid(), 'component_id' => $uuid()->nullable(true), 'code' => new StringType, 'message' => new StringType]);
        $observation = self::object(['component_id' => $uuid(), 'values' => $values]);
        $stage = self::object(['id' => $uuid(), 'inspection_id' => $uuid()->nullable(true), 'reference_date' => $date(), 'measured_on' => $date(), 'verified' => new BooleanType], false);
        $stage->addProperty('observations', self::list($observation));
        $override = self::object(['component_id' => $uuid(), 'is_insulated' => (new BooleanType)->nullable($input)]);
        $result = self::object(['schema_version' => new StringType, 'stages' => self::list($stage), 'insulation_overrides' => self::list($override)], false);
        if ($input) {
            $stage->addProperty('unlock', new BooleanType)->addProperty('reset_components', self::list($uuid()))->setRequired(['id']);
            $result->addProperty('delete_stage_ids', self::list($uuid()))->setRequired(['schema_version']);

            return $result;
        }
        $values->setRequired(array_keys($values->properties));
        $observation->addProperty('category', (new StringType)->enum(['B', 'C', 'E']))->addProperty('kind', (new StringType)->enum(['ext', 'cui'])->nullable(true))
            ->addProperty('depth_percent', (new NumberType)->nullable(true))->addProperty('anomalies', self::list($anomaly));
        $observation->setRequired(array_keys($observation->properties));
        $stage->addProperty('source_date', (new StringType)->format('date'))->addProperty('diagnostic_on', $date())
            ->addProperty('diagnostic_kind', (new StringType)->enum(VisualInspectionSchema::KINDS)->nullable(true))
            ->addProperty('origin', (new StringType)->enum(['inspection', 'manual']))->addProperty('persisted', new BooleanType)
            ->addProperty('verified_by', (new IntegerType)->nullable(true))->addProperty('verified_at', (new StringType)->format('date-time')->nullable(true))
            ->addProperty('date_anomaly', new BooleanType);
        $stage->setRequired(array_keys($stage->properties));
        $element = self::object(['component_id' => $uuid(), 'scope_id' => new StringType, 'current_element' => new BooleanType, 'active' => new BooleanType,
            'nominal_thickness_mm' => (new NumberType)->nullable(true), 'auto_insulated' => (new BooleanType)->nullable(true), 'insulated' => (new BooleanType)->nullable(true),
            'insulation_source' => (new StringType)->enum(['manual', 'flows', 'passport', 'unknown']), 'insulation_requires_review' => new BooleanType, 'coating_applicable' => (new BooleanType)->nullable(true)]);
        $summary = self::object(['scope_id' => new StringType, 'name' => new StringType, 'current_elements' => new IntegerType, 'observed_elements' => new IntegerType,
            'corroded_elements' => new IntegerType, 'unknown_corrosion_elements' => new IntegerType, 'partial_elements' => new IntegerType,
            'max_depth_mm' => (new NumberType)->nullable(true), 'max_depth_percent' => (new NumberType)->nullable(true)]);
        $result->addProperty('current_element_ids', self::list($uuid()))->addProperty('elements', self::list($element))->addProperty('scope_summaries', self::list($summary))->addProperty('anomalies', self::list($anomaly));
        $result->setRequired(array_keys($result->properties));

        return $result;
    }
}
