<?php

namespace App\Modules\Diagnostics\Domain;

use InvalidArgumentException;

/** Category rules from the old application, independently covered by pinned oracle cases. */
final class InspectionCategoryRules
{
    public function thinning(bool $pipeline, bool $internal, int $total, int $measured): ?string
    {
        if ($total < 0 || $measured < 0 || $measured > $total) {
            throw new InvalidArgumentException('Число замеренных CML должно быть от нуля до числа CML элемента.');
        }
        if ($pipeline) {
            return $measured > 0 ? 'C' : 'E';
        }
        if (! $internal) {
            return $measured > 0 ? 'C' : null;
        }

        return $measured === 0 ? 'D' : ($measured === $total ? 'B' : 'C');
    }

    public function visual(array $values, bool $verified): string
    {
        $observed = $verified || count(array_filter($values, fn ($value, $key) => ! in_array($key, ['area_unit', 'notes'], true) && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH)) > 0;

        return $observed ? (($values['partial'] ?? null) === true ? 'C' : 'B') : 'E';
    }
}
