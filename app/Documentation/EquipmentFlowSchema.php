<?php

namespace App\Documentation;

use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class EquipmentFlowSchema
{
    public static function make(): ObjectType
    {
        $link = (new ObjectType)->addProperty('id', (new StringType)->format('uuid'))->addProperty('flow_id', (new StringType)->format('uuid'))
            ->addProperty('share_percent', (new NumberType)->nullable(true))->addProperty('notes', (new StringType)->nullable(true))
            ->addProperty('flow_name', new StringType)->addProperty('flow_category', (new StringType)->enum(['main', 'alternative', 'auxiliary', 'repair', 'external']))
            ->addProperty('flow_version', new IntegerType)->addProperty('accepted_version', new IntegerType)->addProperty('stale', new BooleanType)->addProperty('flow_status', (new StringType)->enum(['active', 'archived']))
            ->setRequired(['id', 'flow_id', 'share_percent', 'notes', 'flow_name', 'flow_category', 'flow_version', 'accepted_version', 'stale', 'flow_status']);
        $check = (new ObjectType)->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['by', 'at'])->nullable(true);
        $contact = (new ObjectType)->addProperty('id', (new StringType)->format('uuid'))->addProperty('scope', (new StringType)->enum(['equipment', 'tube', 'shell', 'component']))
            ->addProperty('component_id', (new StringType)->format('uuid')->nullable(true))->addProperty('side', (new StringType)->enum(['internal', 'external', 'heating', 'insulation']))
            ->addProperty('mode', (new StringType)->enum(['assign', 'none', 'inherit']))->addProperty('notes', (new StringType)->nullable(true))->addProperty('check', $check)->addProperty('created_at', new StringType)
            ->addProperty('flows', (new ArrayType)->setItems($link))->setRequired(['id', 'scope', 'component_id', 'side', 'mode', 'notes', 'check', 'created_at', 'flows']);
        $effective = (new ObjectType)->addProperty('component_id', (new StringType)->format('uuid'))->addProperty('side', (new StringType)->enum(['internal', 'external', 'heating', 'insulation']))
            ->addProperty('active', new BooleanType)->addProperty('contact_id', (new StringType)->format('uuid')->nullable(true))->addProperty('inherited', new BooleanType)
            ->addProperty('mode', (new StringType)->enum(['assign', 'none', 'unknown']))
            ->addProperty('main_share_percent', (new NumberType)->nullable(true))->setRequired(['component_id', 'side', 'active', 'contact_id', 'inherited', 'mode', 'main_share_percent']);

        $selected = (new ObjectType)->addProperty('flow_id', (new StringType)->format('uuid'))->addProperty('flow_name', new StringType)->addProperty('flow_code', (new StringType)->nullable(true))
            ->addProperty('flow_category', (new StringType)->enum(['main', 'alternative', 'auxiliary', 'repair', 'external']))->addProperty('medium_kind', (new StringType)->enum(['fluid', 'air', 'soil', 'insulation']))->addProperty('flow_status', (new StringType)->enum(['active', 'archived']))
            ->setRequired(['flow_id', 'flow_name', 'flow_code', 'flow_category', 'medium_kind', 'flow_status']);

        return (new ObjectType)->addProperty('contacts', (new ArrayType)->setItems($contact))->addProperty('effective', (new ArrayType)->setItems($effective))->addProperty('selected', (new ArrayType)->setItems($selected))->setRequired(['contacts', 'effective', 'selected']);
    }
}
