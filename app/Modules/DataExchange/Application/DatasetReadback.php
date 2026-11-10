<?php

namespace App\Modules\DataExchange\Application;

use App\Modules\Diagnostics\Application\EquipmentInspectionCategories;
use App\Modules\Diagnostics\Application\EquipmentVisualInspections;
use App\Modules\Diagnostics\Domain\VisualInspectionSchema;
use App\Modules\Equipment\Application\EquipmentThickness;
use App\Modules\Equipment\Infrastructure\MechanismFacts;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Installations\Models\Installation;
use App\Modules\Installations\Models\InstallationMaintenanceRecord;
use App\Modules\Rbi\Application\InstallationManagement;
use App\Modules\Rbi\Application\InstallationRiskCriteria;
use App\Modules\Shared\Domain\ValueEquality;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Independently reload the public projections before an initial import can commit. */
final class DatasetReadback
{
    public function installations(array $records): array
    {
        $fields = 0;
        foreach ($records as $record) {
            $path = 'installations.'.$record['id'];
            $model = Installation::findOrFail($record['id']);
            foreach ($record['input'] as $key => $value) {
                if ($key === 'passport') {
                    foreach ($value as $field => $expected) {
                        $this->same($expected, $model->passport[$field] ?? null, $path.'.passport.'.$field, $fields);
                    }
                } else {
                    $this->same($value, $model->getAttribute($key), $path.'.'.$key, $fields);
                }
            }
            if ($record['costs'] !== null) {
                $this->subset($record['costs']['values'] ?? [], $model->cost_values, $path.'.costs.values', $fields);
                $this->subset($record['costs']['sources'] ?? [], $model->cost_sources, $path.'.costs.sources', $fields);
                $this->same([], $model->cost_checks, $path.'.costs.checks', $fields);
            }
            if ($record['management'] !== null) {
                $actual = app(InstallationManagement::class)->read($model);
                $this->subset($record['management']['details'] ?? [], $actual['details'], $path.'.management.details', $fields);
                $answers = array_column($actual['answers'], null, 'question_id');
                foreach ($record['management']['answers'] as $answer) {
                    $this->subset($answer, $answers[$answer['question_id']] ?? [], $path.'.management.'.$answer['question_id'], $fields);
                }
            }
            if ($record['risk_criteria'] !== null) {
                $criteria = app(InstallationRiskCriteria::class)->read($model);
                $this->subset($record['risk_criteria']['values'] ?? [], $criteria['overrides'], $path.'.risk_criteria.values', $fields);
            }
            if ($record['maintenance'] !== null) {
                if (array_key_exists('no_stops', $record['maintenance'])) {
                    $this->same($record['maintenance']['no_stops'], $model->no_stops, $path.'.no_stops', $fields);
                }
                foreach ($record['maintenance']['upserts'] ?? [] as $entry) {
                    $row = InstallationMaintenanceRecord::findOrFail($entry['id']);
                    $entryPath = $path.'.maintenance.'.$entry['id'];
                    $this->same($model->id, $row->installation_id, $entryPath.'.installation_id', $fields);
                    $this->same($entry['kind'], $row->kind, $entryPath.'.kind', $fields);
                    $this->same(null, $row->check, $entryPath.'.check', $fields);
                    $expected = $entry['values'] ?? [];
                    foreach (['starts_at', 'ends_at'] as $key) {
                        if (isset($expected[$key])) {
                            $expected[$key] = Carbon::parse($expected[$key])->toISOString();
                        }
                    }
                    if (isset($expected['interval_months'])) {
                        $expected['interval_months'] = (int) $expected['interval_months'];
                    }
                    $this->subset($expected, $row->values, $entryPath.'.values', $fields);
                }
            }
        }

        return ['installations' => count($records), 'installation_fields' => $fields];
    }

    public function equipment(array $records): array
    {
        $fields = 0;
        $points = 0;
        foreach ($records as $record) {
            $path = 'equipment.'.$record['source_id'];
            $model = Equipment::find($record['id']);
            $this->same($record['id'], $model?->id, $path.'.id', $fields);
            foreach (['installation_id', 'profile_code'] as $key) {
                $this->same($record['create'][$key], $model->getAttribute($key), $path.'.'.$key, $fields);
            }
            // These SQL projections intentionally omit unknown scalar fields.
            foreach ($record['create']['passport'] as $key => $value) {
                $this->same($value, $model->passport[$key] ?? null, $path.'.passport.'.$key, $fields);
            }
            $this->subset($record['create']['passport_qualifiers'] ?? [], $model->passport_qualifiers, $path.'.passport_qualifiers', $fields);
            $this->same([], $model->checks, $path.'.checks', $fields);
            if (($record['mechanisms'] ?? null) !== null) {
                $facts = app(MechanismFacts::class)->read($model->id);
                foreach (MechanismFacts::ROOT_FIELDS as $key) {
                    $this->same($record['mechanisms']['inputs'][$key] ?? null, $facts['inputs'][$key], $path.'.mechanisms.inputs.'.$key, $fields);
                }
                $components = array_column($facts['component_inputs'], null, 'component_id');
                $meaningful = array_filter($record['mechanisms']['component_inputs'] ?? [], fn ($row) => count(array_filter(array_intersect_key($row, array_flip(MechanismFacts::COMPONENT_FIELDS)), fn ($value) => $value !== null)) > 0);
                $this->same(count($meaningful), count($components), $path.'.mechanisms.components.count', $fields);
                foreach ($meaningful as $row) {
                    foreach (MechanismFacts::COMPONENT_FIELDS as $key) {
                        $this->same($row[$key] ?? null, $components[$row['component_id']][$key] ?? null, $path.'.mechanisms.components.'.$row['component_id'].'.'.$key, $fields);
                    }
                }
                foreach (['overrides', 'question_notes', 'reviews'] as $key) {
                    $this->same([], $facts[$key], $path.'.mechanisms.'.$key, $fields);
                }
            }
            if (($record['inspection_categories'] ?? null) !== null) {
                $categories = app(EquipmentInspectionCategories::class)->read($model);
                $actualStages = array_column($categories['stages'], null, 'id');
                foreach ($record['inspection_categories']['stages'] as $stage) {
                    $actual = $actualStages[$stage['id']] ?? [];
                    $stagePath = $path.'.inspection_categories.'.$stage['id'];
                    foreach (['group_code', 'inspection_id', 'reference_date'] as $key) {
                        $this->same($stage[$key], $actual[$key] ?? null, $stagePath.'.'.$key, $fields);
                    }
                    $observations = array_column($actual['observations'] ?? [], null, 'component_id');
                    $meaningful = array_filter($stage['observations'], fn ($row) => ($row['category'] ?? null) !== null || ($row['cracks_present'] ?? null) !== null);
                    $this->same(count($meaningful), count($observations), $stagePath.'.count', $fields);
                    foreach ($meaningful as $observation) {
                        $this->subset(['category' => null, 'cracks_present' => null, ...$observation], $observations[$observation['component_id']] ?? [], $stagePath.'.'.$observation['component_id'], $fields);
                    }
                }
            }
            if (($record['visual_inspections'] ?? null) !== null) {
                $visual = app(EquipmentVisualInspections::class)->read($model);
                $actualStages = array_column($visual['stages'], null, 'id');
                foreach ($record['visual_inspections']['stages'] as $stage) {
                    $actual = $actualStages[$stage['id']] ?? [];
                    $stagePath = $path.'.visual_inspections.'.$stage['id'];
                    foreach (['inspection_id', 'reference_date', 'measured_on', 'verified'] as $key) {
                        $this->same($stage[$key], $actual[$key] ?? null, $stagePath.'.'.$key, $fields);
                    }
                    $this->same(null, $actual['verified_by'] ?? null, $stagePath.'.verified_by', $fields);
                    $this->same(null, $actual['verified_at'] ?? null, $stagePath.'.verified_at', $fields);
                    $observations = array_column($actual['observations'] ?? [], null, 'component_id');
                    $meaningful = array_filter($stage['observations'], fn ($row) => count(array_filter($row['values'], fn ($value) => $value !== null)) > 0);
                    $this->same(count($meaningful), count($observations), $stagePath.'.count', $fields);
                    foreach ($meaningful as $observation) {
                        $this->subset(array_replace(array_fill_keys(array_keys(VisualInspectionSchema::fields()), null), $observation['values']), $observations[$observation['component_id']]['values'] ?? [], $stagePath.'.'.$observation['component_id'], $fields);
                    }
                }
                $actualOverrides = array_column($visual['insulation_overrides'], 'is_insulated', 'component_id');
                $this->same(count($record['visual_inspections']['insulation_overrides']), count($actualOverrides), $path.'.visual_insulation.count', $fields);
                foreach ($record['visual_inspections']['insulation_overrides'] as $override) {
                    $this->same($override['is_insulated'], $actualOverrides[$override['component_id']] ?? null, $path.'.visual_insulation.'.$override['component_id'], $fields);
                }
            }
            $thickness = app(EquipmentThickness::class)->read($model);
            $cmls = array_column($thickness['cmls'], null, 'id');
            $this->same(count($record['cmls']), count($cmls), $path.'.cmls.count', $fields);
            foreach ($record['cmls'] as $cml) {
                $this->subset($cml, $cmls[$cml['id']] ?? [], $path.'.cmls.'.$cml['id'], $fields);
            }
            $stages = array_column($thickness['stages'], null, 'id');
            foreach ($record['stages'] as $stage) {
                $actual = $stages[$stage['id']] ?? [];
                $stagePath = $path.'.stages.'.$stage['id'];
                $this->same($stage['measured_on'], $actual['measured_on'] ?? null, $stagePath.'.measured_on', $fields);
                $this->same(false, $actual['verified'] ?? null, $stagePath.'.verified', $fields);
                $measurements = array_column($actual['measurements'] ?? [], null, 'cml_id');
                $this->same(count($stage['measurements']), count($measurements), $stagePath.'.measurements.count', $fields);
                foreach ($stage['measurements'] as $measurement) {
                    $value = $measurements[$measurement['cml_id']] ?? [];
                    $pointPath = $stagePath.'.'.$measurement['cml_id'].'.points';
                    // Exact coordinate count/order, including explicit null and numeric zero.
                    $this->subset($measurement['points'], $value['points'] ?? [], $pointPath, $fields);
                    $points += count($measurement['points']);
                }
            }
        }

        return ['equipment' => count($records), 'passport_and_thickness_fields' => $fields, 'points' => $points];
    }

    private function subset(array $expected, array $actual, string $path, int &$fields): void
    {
        if (array_is_list($expected) && array_is_list($actual)) {
            $this->same(count($expected), count($actual), $path.'.count', $fields);
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                $this->mismatch($path.'.'.$key);
            }
            if (is_array($value) && is_array($actual[$key])) {
                $this->subset($value, $actual[$key], $path.'.'.$key, $fields);
            } else {
                $this->same($value, $actual[$key], $path.'.'.$key, $fields);
            }
        }
    }

    private function same(mixed $expected, mixed $actual, string $path, int &$fields): void
    {
        // PDO float bindings and PostgreSQL JSON can differ at the final binary digits.
        // Never coerce booleans, strings or null, and keep zero distinct from a tiny value.
        $numericRoundtrip = (is_int($expected) || is_float($expected))
            && (is_int($actual) || is_float($actual))
            && $expected != 0 && $actual != 0
            && abs($expected - $actual) <= 1e-10;
        if (! ValueEquality::same($expected, $actual) && ! $numericRoundtrip) {
            $this->mismatch($path);
        }
        $fields++;
    }

    private function mismatch(string $path): never
    {
        throw ValidationException::withMessages(['readback.'.$path => 'Значение '.$path.' не совпало с каноническим входом импорта. Все изменения отменены.']);
    }
}
