<?php

namespace App\Modules\Installations\Infrastructure;

use App\Modules\Installations\Domain\InstallationPassportSchema;
use App\Modules\Installations\Models\Installation;

final class InstallationPassportRepository
{
    /** Replacement semantics match the original public passport contract. */
    public function save(Installation $installation, array $input): void
    {
        $values = [];
        foreach (InstallationPassportSchema::FIELDS as $key => [$column]) {
            $values[$column] = $input[$key] ?? null;
        }
        $record = $installation->technicalPassport ?? $installation->technicalPassport()->make();
        if (array_filter($values, fn ($value) => $value !== null)) {
            $record->fill($values)->save();
            $installation->setRelation('technicalPassport', $record);
        } else {
            if ($record->exists) {
                $record->delete();
            }
            $installation->setRelation('technicalPassport', null);
        }
    }
}
