<?php

namespace App\Modules\Installations\Application;

use App\Modules\Installations\Models\Installation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class InstallationMaintenanceSummary
{
    public function currentMri(Installation $installation): ?object
    {
        return DB::connection('tenant')->table('installation_maintenance_records as r')
            ->join('installation_mri_parameters as m', 'm.record_id', '=', 'r.id')
            ->where('r.installation_id', $installation->id)->whereNull('r.deleted_at')->where('r.kind', 'mri')
            ->where('m.effective_date', '<=', CarbonImmutable::now('UTC')->toDateString())->whereNotNull('m.interval_months')
            ->orderByDesc('m.effective_date')->orderByDesc('r.id')->first(['r.id', 'm.effective_date', 'm.interval_months']);
    }

    public function describe(Installation $installation): array
    {
        $now = CarbonImmutable::now('UTC');
        $from = $now->subYearNoOverflow();
        $db = DB::connection('tenant');
        $query = fn () => $db->table('installation_maintenance_records as r')->where('r.installation_id', $installation->id)->whereNull('r.deleted_at');
        $mri = $this->currentMri($installation);
        $capital = $query()->join('installation_repair_parameters as p', 'p.record_id', '=', 'r.id')->where('r.kind', 'repair')->where('p.repair_kind', 'capital')->whereNotNull('p.starts_on')->where('p.ends_on', '<=', $now->toDateString())->orderByDesc('p.ends_on')->orderByDesc('r.id')->first(['p.ends_on']);
        $months = $mri?->interval_months;
        $next = $capital && $months ? CarbonImmutable::parse($capital->ends_on, 'UTC')->addMonthsNoOverflow($months) : null;
        $following = $next && $months ? $next->addMonthsNoOverflow($months) : null;
        $counts = $query()->selectRaw('r.kind, count(*) AS total, count(*) FILTER (WHERE r.verified_at IS NOT NULL) AS verified')->groupBy('r.kind')->get()->keyBy('kind');
        // Multirange union prevents double-counting overlapping incidents. Open/incomplete records remain unknown.
        $downtime = DB::connection('tenant')->selectOne(<<<'SQL'
            WITH periods AS (
                SELECT tstzrange(greatest(s.starts_at, ?::timestamptz), least(s.ends_at, ?::timestamptz), '[)') AS span
                FROM installation_maintenance_records r JOIN installation_stop_parameters s ON s.record_id=r.id
                WHERE r.installation_id = ? AND r.kind = 'stop' AND r.deleted_at IS NULL
                  AND s.starts_at IS NOT NULL AND s.ends_at IS NOT NULL
                  AND s.starts_at < ?::timestamptz AND s.ends_at > ?::timestamptz
            ), merged AS (SELECT unnest(range_agg(span)) AS span FROM periods)
            SELECT coalesce((SELECT sum(extract(epoch FROM upper(span) - lower(span))) FROM merged), 0) AS seconds,
                   coalesce((SELECT sum(extract(epoch FROM upper(span) - lower(span))) FROM periods), 0) AS raw_seconds
            SQL, [$from->toISOString(), $now->toISOString(), $installation->id, $now->toISOString(), $from->toISOString()]);
        $incompleteStops = $query()->where('r.kind', 'stop')->leftJoin('installation_stop_parameters as s', 's.record_id', '=', 'r.id')->where(fn ($query) => $query->whereNull('s.starts_at')->orWhereNull('s.ends_at'))->count();
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
        if ($mri && $mri->effective_date > $now->toDateString()) {
            $warnings[] = 'Последняя запись МРИ датирована будущим; проверьте дату.';
        }
        if ($next && $next->lt($now->startOfDay())) {
            $warnings[] = 'Плановая дата капитального ремонта прошла. Она не сдвигается автоматически.';
        }

        return ['as_of' => $now->toISOString(), 'timezone' => 'UTC', 'version' => (int) $installation->version, 'no_stops' => $installation->no_stops,
            'counts' => array_map(fn ($kind) => ['kind' => $kind, 'total' => (int) ($counts[$kind]->total ?? 0), 'verified' => (int) ($counts[$kind]->verified ?? 0)], ['mri', 'repair', 'stop']),
            'current_mri_months' => $months, 'current_mri_on' => $mri?->effective_date, 'current_mri_id' => $mri?->id,
            'last_capital_ended_on' => $capital?->ends_on, 'planned_capital_on' => $next?->toDateString(), 'following_capital_on' => $following?->toDateString(),
            'window_from' => $from->toISOString(), 'downtime_hours' => $hours, 'incomplete_stops' => $incompleteStops,
            'downtime_complete' => $complete, 'prodcost' => $cost, 'production_loss' => $cost === null || ! $complete ? null : $hours / 24 * $cost,
            'known_production_loss' => $cost === null ? null : $hours / 24 * $cost, 'warnings' => $warnings];
    }
}
