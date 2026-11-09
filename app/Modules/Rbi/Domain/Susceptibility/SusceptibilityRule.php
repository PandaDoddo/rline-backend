<?php

namespace App\Modules\Rbi\Domain\Susceptibility;

interface SusceptibilityRule
{
    public function evaluate(array $component, array $flow, array $tables): SusceptibilityResult;
}
