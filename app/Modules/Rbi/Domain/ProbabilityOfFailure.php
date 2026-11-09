<?php

namespace App\Modules\Rbi\Domain;

use InvalidArgumentException;

final class ProbabilityOfFailure
{
    public const VERSION = 'pof-old-v1';

    public function calculate(?float $gff, ?float $managementScore, ?string $internalType, ?string $externalType, array $factors, array $activeMechanisms): array
    {
        if ($gff !== null && (! is_finite($gff) || $gff < 0)) {
            throw new InvalidArgumentException('gff должен быть конечным неотрицательным числом.');
        }
        if ($managementScore !== null && (! is_finite($managementScore) || $managementScore < 0 || $managementScore > 100)) {
            throw new InvalidArgumentException('Оценка системы управления должна быть от 0 до 100 %.');
        }
        $df = (new DamageFactorAggregator)->calculate($factors, $activeMechanisms, $internalType, $externalType);
        $missing = $df['missing'];
        if ($gff === null) {
            $missing[] = 'gff';
        }
        if ($managementScore === null) {
            $missing[] = 'management_score';
        }
        // Retain the coefficient used by the versioned legacy workbook kernel.
        // No implicit management score of 72 is assigned to real installations.
        $factor = $managementScore === null ? null : 2.38 * pow(2.7182818284, -0.012 * $managementScore);
        $pof = $missing === [] ? $gff * $factor * $df['total'] : null;
        if ($pof !== null && ! is_finite($pof)) {
            throw new InvalidArgumentException('Переполнение при расчёте PoF.');
        }

        return ['method' => self::VERSION, 'ready' => $missing === [], 'missing' => $missing,
            'res' => ['gff' => $gff, 'Fms' => $factor, 'pscore' => $managementScore] + $df['groups'] + ['Dft' => $df['total'], 'loc' => $df['local'], 'PoF' => $pof]];
    }
}
