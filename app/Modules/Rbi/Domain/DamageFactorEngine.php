<?php

namespace App\Modules\Rbi\Domain;

interface DamageFactorEngine
{
    public function calculate(string $mechanism, array $input, array $tables): array;
}
