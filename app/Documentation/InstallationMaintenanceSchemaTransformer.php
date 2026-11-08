<?php

namespace App\Documentation;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class InstallationMaintenanceSchemaTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $values = (new ObjectType)->addProperty('effective_date', (new StringType)->format('date')->nullable(true))->addProperty('interval_months', (new IntegerType)->setMin(1)->setMax(1200)->nullable(true))
            ->addProperty('starts_on', (new StringType)->format('date')->nullable(true))->addProperty('ends_on', (new StringType)->format('date')->nullable(true))
            ->addProperty('repair_kind', (new StringType)->enum(['capital', 'extended_medium', 'medium'])->nullable(true))
            ->addProperty('starts_at', (new StringType)->format('date-time')->nullable(true))->addProperty('ends_at', (new StringType)->format('date-time')->nullable(true))->addProperty('reason', (new StringType)->nullable(true));
        foreach ($document->components->schemas as $name => $schema) {
            if (class_basename($name) === 'InstallationMaintenanceSummaryResource') {
                $schema->type = new ObjectType;
            }
            $type = $schema->type;
            if (! $type instanceof ObjectType) {
                continue;
            }
            if (class_basename($name) === 'InstallationMaintenanceResource') {
                $type->addProperty('kind', (new StringType)->enum(['mri', 'repair', 'stop']))->addProperty('values', $values->clone());
                $type->addProperty('check', (new ObjectType)->addProperty('by', new IntegerType)->addProperty('at', (new StringType)->format('date-time'))->setRequired(['by', 'at'])->nullable(true));
                $type->addProperty('duration_hours', (new NumberType)->nullable(true));
            }
            if (class_basename($name) === 'UpdateInstallationMaintenanceRequest') {
                $item = (new ObjectType)->addProperty('id', (new StringType)->format('uuid'))->addProperty('kind', (new StringType)->enum(['mri', 'repair', 'stop']))
                    ->addProperty('values', $values->clone())->addProperty('verified', new BooleanType)->addProperty('unlock', new BooleanType)->setRequired(['id', 'kind']);
                $type->addProperty('upserts', (new ArrayType)->setItems($item))->setRequired(['version']);
            }
            if (class_basename($name) === 'InstallationMaintenanceSummaryResource') {
                $type->addProperty('as_of', (new StringType)->format('date-time'))->addProperty('window_from', (new StringType)->format('date-time'))->addProperty('timezone', new StringType)->addProperty('version', new IntegerType)->addProperty('no_stops', (new BooleanType)->nullable(true));
                $count = (new ObjectType)->addProperty('kind', (new StringType)->enum(['mri', 'repair', 'stop']))->addProperty('total', new IntegerType)->addProperty('verified', new IntegerType)->setRequired(['kind', 'total', 'verified']);
                $type->addProperty('counts', (new ArrayType)->setItems($count));
                foreach (['current_mri_on', 'last_capital_ended_on', 'planned_capital_on', 'following_capital_on'] as $key) {
                    $type->addProperty($key, (new StringType)->format('date')->nullable(true));
                }
                $type->addProperty('current_mri_months', (new IntegerType)->nullable(true))->addProperty('current_mri_id', (new StringType)->format('uuid')->nullable(true));
                foreach (['downtime_hours', 'prodcost', 'production_loss', 'known_production_loss'] as $key) {
                    $type->addProperty($key, (new NumberType)->nullable($key !== 'downtime_hours'));
                }
                $type->addProperty('incomplete_stops', new IntegerType)->addProperty('warnings', (new ArrayType)->setItems(new StringType));
                $type->addProperty('downtime_complete', new BooleanType);
                $type->setRequired(array_keys($type->properties));
            }
        }
    }
}
