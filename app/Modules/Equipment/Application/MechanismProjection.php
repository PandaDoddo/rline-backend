<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Equipment\Domain\Screening\MechanismAssessment;
use App\Modules\Equipment\Infrastructure\MechanismFacts;
use App\Modules\Equipment\Models\Equipment;

final class MechanismProjection
{
    public function __construct(private readonly MechanismFacts $facts, private readonly MechanismContextProvider $contexts, private readonly MechanismAssessment $assessment) {}

    public function read(Equipment $equipment, array $flows, array $scopes, array $visual, bool $lock = false, ?array $facts = null): array
    {
        $facts ??= $this->facts->read($equipment->id);
        $factory = $this->contexts->factory($equipment, $flows, $scopes, $visual, $facts, $lock);

        return [...$facts, ...$this->assessment->evaluate($factory, $visual['elements'], $facts, config('equipment.profiles')[$equipment->profile_code]['g'] === 'pipe')];
    }
}
