<?php

namespace App\Domain;

use App\Models\Installation;
use App\Models\InstallationMaintenanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class InstallationMaintenanceSummary
{
    public function describe(Installation $installation): array
    {
        $now = CarbonImmutable::now('UTC');
        $from = $now->subYearNoOverflow();
        $query = fn () => InstallationMaintenanceRecord::where('installation_id', $installation->id);
        $mri = $query()->where('kind', 'mri')->whereRaw("installation_maintenance_records.values->>'effective_date' IS NOT NULL AND installation_maintenance_records.values->>'interval_months' IS NOT NULL")->orderByRaw("installation_maintenance_records.values->>'effective_date' DESC")->orderByDesc('id')->first();
        $capital = $query()->where('kind', 'repair')->whereRaw("installation_maintenance_records.values->>'repair_kind' = 'capital' AND installation_maintenance_records.values->>'starts_on' IS NOT NULL AND installation_maintenance_records.values->>'ends_on' <= ?", [$now->toDateString()])->orderByRaw("installation_maintenance_records.values->>'ends_on' DESC")->orderByDesc('id')->first();
        $months = $mri?->values['interval_months'];
        $next = $capital && $months ? CarbonImmutable::parse($capital->values['ends_on'], 'UTC')->addMonthsNoOverflow($months) : null;
        $following = $next && $months ? $next->addMonthsNoOverflow($months) : null;
        $counts = $query()->selectRaw('kind, count(*) AS total, count(*) FILTER (WHERE "check" IS NOT NULL) AS verified')->groupBy('kind')->get()->keyBy('kind');
        // Multirange union prevents double-counting overlapping incidents. Open/incomplete records remain unknown.
        $downtime = DB::connection('tenant')->selectOne(<<<'SQL'
            WITH periods AS (
                SELECT tstzrange(greatest((installation_maintenance_records.values->>'starts_at')::timestamptz, ?::timestamptz), least((installation_maintenance_records.values->>'ends_at')::timestamptz, ?::timestamptz), '[)') AS span
                FROM installation_maintenance_records
                WHERE installation_id = ? AND kind = 'stop' AND deleted_at IS NULL
                  AND installation_maintenance_records.values->>'starts_at' IS NOT NULL AND installation_maintenance_records.values->>'ends_at' IS NOT NULL
                  AND (installation_maintenance_records.values->>'starts_at')::timestamptz < ?::timestamptz AND (installation_maintenance_records.values->>'ends_at')::timestamptz > ?::timestamptz
            ), merged AS (SELECT unnest(range_agg(span)) AS span FROM periods)
            SELECT coalesce((SELECT sum(extract(epoch FROM upper(span) - lower(span))) FROM merged), 0) AS seconds,
                   coalesce((SELECT sum(extract(epoch FROM upper(span) - lower(span))) FROM periods), 0) AS raw_seconds
            SQL, [$from->toISOString(), $now->toISOString(), $installation->id, $now->toISOString(), $from->toISOString()]);
        $incompleteStops = $query()->where('kind', 'stop')->whereRaw("(installation_maintenance_records.values->>'starts_at' IS NULL OR installation_maintenance_records.values->>'ends_at' IS NULL)")->count();
        $hours = (float) $downtime->seconds / 3600;
        $cost = $installation->cost_values['prodcost'] ?? null;
        $complete = $incompleteStops === 0 && ((int) ($counts['stop']->total ?? 0) > 0 || $installation->no_stops === true);
        $warnings = [];
        if ($incompleteStops) {
            $warnings[] = 'Неполных остановов: '.$incompleteStops.'. Длительность и потери рассчитаны только по заполненным датам.';
        }
        if ((float) $downtime->raw_seconds > (float) $downtime->seconds) {
            $warnings[] = 'Есть пересекающиеся остановы; общее время посчитано без повторов.';
        }
        if ($mri && $mri->values['effective_date'] > $now->toDateString()) {
            $warnings[] = 'Последняя запись МРИ датирована будущим; проверьте дату.';
        }
        if ($next && $next->lt($now->startOfDay())) {
            $warnings[] = 'Плановая дата капитального ремонта прошла. Она не сдвигается автоматически.';
        }

        return ['as_of' => $now->toISOString(), 'timezone' => 'UTC', 'version' => (int) $installation->version, 'no_stops' => $installation->no_stops,
            'counts' => array_map(fn ($kind) => ['kind' => $kind, 'total' => (int) ($counts[$kind]->total ?? 0), 'verified' => (int) ($counts[$kind]->verified ?? 0)], ['mri', 'repair', 'stop']),
            'current_mri_months' => $months, 'current_mri_on' => $mri?->values['effective_date'], 'current_mri_id' => $mri?->id,
            'last_capital_ended_on' => $capital?->values['ends_on'], 'planned_capital_on' => $next?->toDateString(), 'following_capital_on' => $following?->toDateString(),
            'window_from' => $from->toISOString(), 'downtime_hours' => $hours, 'incomplete_stops' => $incompleteStops,
            'downtime_complete' => $complete, 'prodcost' => $cost, 'production_loss' => $cost === null || ! $complete ? null : $hours / 24 * $cost,
            'known_production_loss' => $cost === null ? null : $hours / 24 * $cost, 'warnings' => $warnings];
    }
}
