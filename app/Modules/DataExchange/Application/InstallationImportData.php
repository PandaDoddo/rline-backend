<?php

namespace App\Modules\DataExchange\Application;

use App\Modules\Installations\Models\Installation;
use Illuminate\Support\Facades\DB;

/** Read adapter for HTTP surfaces; calculation and write services do not depend on import metadata. */
final class InstallationImportData
{
    public function __construct(private readonly ImportedRecordOrigins $origins) {}

    public function describe(Installation $installation, bool $includeIssues = false): array
    {
        $this->origins->attach(collect([$installation]), 'installation');
        $origin = $installation->getAttribute('import_origin');
        if (! $includeIssues) {
            return ['data_origin' => $origin];
        }
        $issues = $origin ? DB::connection('tenant')->table('dataset_import_issues')->where('installation_id', $installation->id)->where('batch_id', $origin['batch_id'])
            ->whereIn('severity', ['warning', 'error'])->orderByRaw("CASE severity WHEN 'error' THEN 0 ELSE 1 END")->orderBy('source_path')->orderBy('id')->limit(200)
            ->get(['source_path', 'kind', 'severity', 'message'])->map(fn ($row) => (array) $row)->all() : [];

        return ['data_origin' => $origin, 'source_issues' => $issues, 'source_issues_truncated' => ($origin['source_issue_count'] ?? 0) > count($issues)];
    }
}
