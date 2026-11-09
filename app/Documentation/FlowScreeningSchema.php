<?php

namespace App\Documentation;

use App\Modules\ProcessFlows\Domain\FlowScreening;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class FlowScreeningSchema
{
    public static function baseline(): ObjectType
    {
        $condition = (new ObjectType)->addProperty('field', new StringType)->addProperty('operator', (new StringType)->enum(['yes', '>', '<', 'in']))->addProperty('threshold', (new ArrayType)->setItems(new NumberType))->addProperty('near', (new ArrayType)->setItems(new NumberType))->setRequired(['field', 'operator', 'threshold', 'near']);
        $question = (new ObjectType)->addProperty('id', new StringType)->addProperty('label', new StringType)->addProperty('english_label', new StringType)->addProperty('scope', (new StringType)->enum(['flow', 'component']))->addProperty('conditions', (new ArrayType)->setItems($condition))->setRequired(['id', 'label', 'english_label', 'scope', 'conditions']);
        $mechanism = (new ObjectType)->addProperty('id', new StringType)->addProperty('label', new StringType)->addProperty('english_label', new StringType)->addProperty('source', new StringType)->addProperty('damage_mechanisms', (new ArrayType)->setItems(new StringType))->addProperty('component_only', new BooleanType)->addProperty('questions', (new ArrayType)->setItems($question));
        $mechanism->setRequired(array_keys($mechanism->properties));
        $field = (new ObjectType)->addProperty('id', new StringType)->addProperty('label', new StringType)->addProperty('english_label', new StringType)->addProperty('type', (new StringType)->enum(['number', 'boolean']))->addProperty('unit', new StringType)->addProperty('min', new NumberType)->addProperty('max', new NumberType);
        $field->setRequired(array_keys($field->properties));

        return (new ObjectType)->addProperty('version', new StringType)->addProperty('source', new StringType)->addProperty('fields', (new ArrayType)->setItems($field))->addProperty('mechanisms', (new ArrayType)->setItems($mechanism))->setRequired(['version', 'source', 'fields', 'mechanisms']);
    }

    public static function state(bool $input = false): ObjectType
    {
        $values = new ObjectType;
        foreach (app(FlowScreening::class)->schema()['fields'] as $field) {
            $values->addProperty($field['id'], ($field['type'] === 'boolean' ? new BooleanType : (new NumberType)->setMin($field['min'])->setMax($field['max']))->nullable(true));
        }
        $comments = new ObjectType;
        $comments->additionalProperties = (new StringType)->nullable(true);
        $thresholds = new ObjectType;
        $thresholds->additionalProperties = (new ObjectType)->addProperty('threshold', (new ArrayType)->setItems(new NumberType))->addProperty('near', (new ArrayType)->setItems(new NumberType))->nullable(true);
        $state = (new ObjectType)->addProperty('values', $values)->addProperty('comments', $comments)->addProperty('thresholds', $thresholds);
        if (! $input) {
            $state->addProperty('updated_by', new IntegerType)->addProperty('updated_at', (new StringType)->format('date-time'));
        }

        return $state;
    }

    public static function result(): ObjectType
    {
        $condition = (new ObjectType)->addProperty('field', new StringType)->addProperty('operator', (new StringType)->enum(['yes', '>', '<', 'in']))
            ->addProperty('value', (new AnyOf)->setItems([(new NumberType)->nullable(true), new BooleanType]))
            ->addProperty('threshold', (new ArrayType)->setItems(new NumberType))->addProperty('near', (new ArrayType)->setItems(new NumberType))
            ->addProperty('status', (new StringType)->enum(['active', 'inactive', 'unknown', 'attention', 'component_required']))->addProperty('reason', (new StringType)->nullable(true));
        $condition->setRequired(array_keys($condition->properties));
        $question = (new ObjectType)->addProperty('id', new StringType)->addProperty('label', new StringType)->addProperty('scope', (new StringType)->enum(['flow', 'component']))
            ->addProperty('comment', (new StringType)->nullable(true))->addProperty('overridden', new BooleanType)->addProperty('conditions', (new ArrayType)->setItems($condition));
        $question->setRequired(array_keys($question->properties));
        $mechanism = (new ObjectType)->addProperty('id', new StringType)->addProperty('label', new StringType)->addProperty('source', new StringType)
            ->addProperty('status', (new StringType)->enum(['active', 'potential', 'inactive']))->addProperty('component_refinement_required', new BooleanType)->addProperty('questions', (new ArrayType)->setItems($question));
        $mechanism->setRequired(array_keys($mechanism->properties));

        return (new ObjectType)->addProperty('engine_version', new StringType)->addProperty('signature', new StringType)->addProperty('mechanisms', (new ArrayType)->setItems($mechanism))->setRequired(['engine_version', 'signature', 'mechanisms']);
    }
}
