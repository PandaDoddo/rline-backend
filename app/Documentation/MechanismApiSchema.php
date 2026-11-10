<?php

namespace App\Documentation;

use App\Modules\Equipment\Domain\Screening\ComponentScreeningSchema;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class MechanismApiSchema
{
    private static function object(array $fields, bool $required = true): ObjectType
    {
        $type = new ObjectType;
        foreach ($fields as $key => $field) {
            $type->addProperty($key, $field);
        }
        if ($required) {
            $type->setRequired(array_keys($fields));
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
        $signature = fn () => (new StringType)->setDescription('SHA-256 входов и версии алгоритма');
        $text = fn () => (new StringType)->nullable(true);
        $schema = ComponentScreeningSchema::metadata();
        $codes = array_column($schema['mechanisms'], 'id');
        $states = fn () => (new StringType)->enum(['active', 'potential', 'inactive']);
        $side = fn () => (new StringType)->enum(['internal', 'external', 'through']);
        $settings = self::object(['vibration_present' => (new BooleanType)->nullable(true), 'hydrogen_partial_pressure_mpa' => (new NumberType)->nullable(true)->setMin(0)->setMax(100000), 'vibration_notes' => $text(), 'hydrogen_notes' => $text()], ! $input);
        $components = self::object(['component_id' => $uuid(), 'vibration_excluded' => (new BooleanType)->nullable(true), 'tank_floor' => (new BooleanType)->nullable(true), 'vibration_notes' => $text(), 'tank_floor_notes' => $text()], ! $input);
        if ($input) {
            $components->setRequired(['component_id']);
        }
        $override = self::object(['component_id' => $uuid(), 'mechanism_code' => (new StringType)->enum($codes), 'side' => $side(), 'state' => $states()->nullable($input), 'reason' => $input ? $text() : new StringType, 'source_signature' => $input ? $signature()->nullable(true) : $signature()], ! $input);
        $note = self::object(['component_id' => $uuid(), 'question_id' => new StringType, 'side' => $side(), 'flow_id' => $uuid()->nullable(true), 'note' => $input ? $text() : new StringType]);
        $review = $input ? self::object(['component_id' => $uuid(), 'verified' => new BooleanType, 'input_signature' => $signature()->nullable(true)], false)->setRequired(['component_id', 'verified'])
            : self::object(['component_id' => $uuid(), 'input_signature' => $signature(), 'verified_by' => new IntegerType, 'verified_at' => (new StringType)->format('date-time')]);
        if ($input) {
            $override->setRequired(['component_id', 'mechanism_code', 'side', 'state']);
        } else {
            foreach ([$override, $note] as $field) {
                $field->addProperty('updated_by', new IntegerType)->addProperty('updated_at', (new StringType)->format('date-time'));
                $field->setRequired(array_keys($field->properties));
            }
            $override->addProperty('stale', new BooleanType)->setRequired([...$override->required, 'stale']);
        }
        $result = self::object(['schema_version' => new StringType, 'inputs' => $settings, 'component_inputs' => self::list($components), 'overrides' => self::list($override), 'question_notes' => self::list($note), 'reviews' => self::list($review)], ! $input);
        if ($input) {
            return $result->setRequired(['schema_version']);
        }
        $scalar = fn () => (new AnyOf)->setItems([(new NumberType)->nullable(true), new BooleanType, new StringType]);
        $condition = self::object(['status' => (new StringType)->enum(['active', 'inactive', 'attention', 'unknown']), 'value' => $scalar(), 'source' => new StringType]);
        $condition->addProperty('threshold', self::list(new NumberType))->addProperty('near', self::list(new NumberType));
        $question = clone $condition;
        $question->addProperty('id', new StringType)->addProperty('label', new StringType)->addProperty('conditions', self::list($condition))->setRequired([...$condition->required, 'id', 'label']);
        $run = self::object(['flow_id' => $uuid()->nullable(true), 'condition' => (new StringType)->enum(['active', 'inactive', 'attention', 'unknown']), 'questions' => self::list($question)]);
        $overrideSummary = self::object(['state' => $states(), 'reason' => new StringType])->nullable(true);
        $surface = self::object(['side' => $side(), 'automatic_state' => $states(), 'state' => $states(), 'condition' => (new StringType)->enum(['active', 'inactive', 'attention', 'unknown']), 'override' => $overrideSummary, 'flow_count' => new IntegerType, 'runs' => self::list($run)]);
        $method = self::object(['id' => (new StringType)->enum($codes), 'label' => new StringType, 'group' => (new StringType)->enum(['2.B', '2.C', '2.D', '2.E']), 'component_only' => new BooleanType, 'state' => $states(), 'automatic_state' => $states(), 'sides' => self::list($surface)]);
        $material = self::object(['id' => $uuid()->nullable(true), 'name' => $text(), 'version' => (new IntegerType)->nullable(true), 'resolved' => new BooleanType]);
        $verified = self::object(['verified' => new BooleanType, 'outdated' => new BooleanType, 'verified_by' => (new IntegerType)->nullable(true), 'verified_at' => (new StringType)->format('date-time')->nullable(true)]);
        $element = self::object(['component_id' => $uuid(), 'scope_id' => new StringType, 'active' => new BooleanType, 'complete' => new BooleanType, 'source_signature' => $signature()->nullable(true), 'calculation_signature' => $signature()->nullable(true), 'input_signature' => $signature()->nullable(true), 'material_ref' => $material, 'mechanisms' => self::list($method), 'review' => $verified]);
        $result->addProperty('engine_version', new StringType)->addProperty('complete', new BooleanType)->addProperty('elements', self::list($element));
        $result->setRequired(array_keys($result->properties));

        return $result;
    }
}
