<?php

namespace App\Modules\Rbi\Domain\Susceptibility;

final readonly class SusceptibilityResult
{
    public function __construct(public ?string $value, public string $reason, public array $missing = [], public array $details = []) {}

    public static function blocked(array $fields, string $reason = 'Не хватает исходных данных.'): self
    {
        return new self(null, $reason, $fields);
    }
}
