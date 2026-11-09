<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

/** Strategy registry; unsupported active mechanisms never contribute a confirmed zero. */
final class DamageFactorDispatcher implements DamageFactorEngine
{
    public function calculate(string $mechanism, array $input, array $tables): array
    {
        return match ($mechanism) {
            'thin' => (new ThinningDamageFactor)->calculate($input),
            'ext','cui' => (new ExternalCorrosionDamageFactor)->calculate($input, $mechanism),
            'ecl','ccl' => (new ExternalChlorideDamageFactor)->calculate($input + ['susT' => $tables[$mechanism === 'ecl' ? 'externalClSusceptibility' : 'cuiClSusceptibility'] ?? null, 'svT' => $tables['externalClSeverity'] ?? null, 't13' => $tables['t13'] ?? null], $mechanism),
            'ssc','hich2s','hichf','hschf' => (new WetSourCrackingDamageFactor)->calculate($input, $mechanism, $tables),
            'acscc','aminescc','causticscc','clscc','pascc','htha' => (new EnvironmentalCrackingDamageFactor)->calculate($input, $mechanism, $tables),
            'bf','lase','e885','sigma' => (new EmbrittlementDamageFactor)->calculate($input, $mechanism, $tables),
            'mfat' => ['method' => 'df-mechanical-fatigue-unavailable', 'ready' => false, 'missing' => ['mechanical_fatigue_method'], 'res' => ['DF' => null], 'steps' => []],
            default => throw new InvalidArgumentException('Неизвестный механизм повреждения.'),
        };
    }
}
