<?php

namespace App\Modules\Equipment\Application;

use App\Modules\Diagnostics\Application\EquipmentDiagnostics;
use App\Modules\Diagnostics\Application\EquipmentInspectionCategories;
use App\Modules\Diagnostics\Application\EquipmentVisualInspections;
use App\Modules\Equipment\Domain\PassportSchema;
use App\Modules\Equipment\Infrastructure\EquipmentPassportRepository;
use App\Modules\Equipment\Infrastructure\MechanismFacts;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Installations\Models\Installation;
use App\Modules\ProcessFlows\Application\EquipmentFlowContacts;
use App\Modules\ProcessFlows\Application\EquipmentFlowSelection;
use App\Modules\Shared\Domain\ValueEquality;
use App\Modules\Shared\Infrastructure\TenantWrites;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Aggregate commands shared by HTTP and validated transactional imports. */
final class EquipmentCommands
{
    public function __construct(
        private readonly TenantWrites $writes,
        private readonly EquipmentPassportRepository $passports,
        private readonly EquipmentDiagnostics $diagnostics,
        private readonly EquipmentThickness $thickness,
        private readonly EquipmentStructure $structure,
        private readonly EquipmentOperatingScopes $scopes,
        private readonly EquipmentFlowContacts $flows,
        private readonly EquipmentMechanisms $mechanisms,
        private readonly EquipmentInspectionCategories $categories, private readonly EquipmentVisualInspections $visual,
    ) {}

    public function create(array $data, int $actor, ?string $identity = null): Equipment
    {
        return $this->writes->transaction(function () use ($data, $actor, $identity) {
            abort_unless(Installation::whereKey($data['installation_id'])->where('status', 'active')->lockForUpdate()->exists(), 422, 'Установка находится в архиве.');
            $this->applicability($data['profile_code'], $data['passport'], $data['passport_qualifiers'] ?? []);
            $model = new Equipment($data);
            if ($identity !== null) {
                if (! Str::isUuid($identity)) {
                    throw ValidationException::withMessages(['id' => 'Идентификатор ЕО должен быть UUID.']);
                }
                $model->forceFill(['id' => $identity]);
            }
            $model->forceFill(PassportSchema::core($data['passport']) + ['created_by' => $actor, 'updated_by' => $actor])->save();
            $this->passports->save($model, $data['passport'], $data['passport_qualifiers'] ?? [], []);
            $this->diagnostics->ensurePrimary($model);
            $this->thickness->ensureStages($model);
            $this->writes->event('equipment', $model, 'created', $actor);

            return $model->load(['installation.parent.parent.parent', ...ComponentSpecifications::eagerLoads(true), 'inspections']);
        });
    }

    public function update(string $equipment, array $data, int $actor): Equipment
    {
        if (array_key_exists('profile_code', $data)) {
            throw ValidationException::withMessages(['profile_code' => 'Смена профиля требует отдельного переноса связанных данных.']);
        }
        $sourceInstallation = Equipment::without('passportProjection')->findOrFail($equipment)->installation_id;

        return $this->writes->transaction(function () use ($equipment, $data, $actor, $sourceInstallation) {
            Installation::whereIn('id', [$sourceInstallation, $data['installation_id']])->orderBy('id')->lockForUpdate()->get();
            $model = Equipment::whereKey($equipment)->lockForUpdate()->firstOrFail();
            abort_unless(Installation::whereKey($data['installation_id'])->where('status', 'active')->lockForUpdate()->exists(), 422, 'Установка находится в архиве.');
            $this->writes->version($model, $data['version']);
            $qualifiers = $data['passport_qualifiers'] ?? $model->passport_qualifiers;
            $this->applicability($model->profile_code, $data['passport'], $qualifiers);
            $before = $model->load([...ComponentSpecifications::eagerLoads(true), 'inspections'])->toArray();
            if (array_key_exists('operating_scopes', $data)) {
                $before['operating_scopes'] = $this->scopes->read($model);
            }
            $flowChange = array_key_exists('flow_contacts', $data) || array_key_exists('flow_selection', $data) || $model->installation_id !== $data['installation_id'];
            if ($flowChange) {
                $before['flows'] = $this->flows->read($model);
            }
            if (array_key_exists('thickness', $data)) {
                $before['thickness'] = $this->thickness->read($model);
            }
            if (array_key_exists('visual_inspections', $data)) {
                $before['visual_inspections'] = $this->visual->read($model);
            }
            if (array_key_exists('inspection_categories', $data)) {
                $before['inspection_categories'] = $this->categories->read($model);
            }
            if (array_key_exists('mechanisms', $data)) {
                $before['mechanisms'] = app(MechanismFacts::class)->read($model->id);
            }
            if (array_key_exists('structure', $data)) {
                $this->structure->apply($model, $data['structure'], $actor);
            }
            if (array_key_exists('inspections', $data)) {
                $this->categories->guardDiagnostics($model, $data['inspections'], $data['inspection_categories']['delete_stage_ids'] ?? []);
                $this->diagnostics->apply($model, $data['inspections'], $actor, $data['visual_inspections']['delete_stage_ids'] ?? []);
            }
            $checks = $model->checks ?? [];
            foreach (array_unique([...array_keys($model->passport), ...array_keys($data['passport']), ...array_keys($model->passport_qualifiers), ...array_keys($qualifiers)]) as $key) {
                if (! ValueEquality::same($model->passport[$key] ?? null, $data['passport'][$key] ?? null) || ! ValueEquality::same($model->passport_qualifiers[$key] ?? null, $qualifiers[$key] ?? null)) {
                    unset($checks[$key]);
                }
            }
            foreach ($data['verification'] ?? [] as $key => $checked) {
                if ($checked) {
                    $value = $data['passport'][$key] ?? null;
                    if (($value === null || $value === '') && ! isset($qualifiers[$key]['state'])) {
                        throw ValidationException::withMessages(['verification.'.$key => 'Нельзя проверить незаполненное поле.']);
                    }
                    $checks[$key] = $checks[$key] ?? ['by' => $actor, 'at' => now()->toIso8601String()];
                } else {
                    unset($checks[$key]);
                }
            }
            $model->fill($data)->forceFill(PassportSchema::core($data['passport']) + ['version' => $model->version + 1, 'updated_by' => $actor])->save();
            $this->passports->save($model, $data['passport'], $qualifiers, $checks);
            $this->diagnostics->ensurePrimary($model);
            $this->thickness->ensureStages($model);
            if (array_key_exists('thickness', $data)) {
                $this->thickness->apply($model, $data['thickness'], $actor);
            }
            $model->load([...ComponentSpecifications::eagerLoads(true), 'inspections']);
            if (array_key_exists('operating_scopes', $data)) {
                $this->scopes->apply($model, $data['operating_scopes'], $actor);
            }
            if ($flowChange) {
                $this->flows->apply($model, $data['flow_contacts'] ?? [], $actor);
                app(EquipmentFlowSelection::class)->apply($model, $data['flow_selection'] ?? null, $actor);
            }
            if (array_key_exists('visual_inspections', $data)) {
                $this->visual->apply($model, $data['visual_inspections'], $actor);
            }
            if (array_key_exists('inspection_categories', $data)) {
                $this->categories->apply($model, $data['inspection_categories'], $actor);
            }
            if (array_key_exists('mechanisms', $data)) {
                $this->mechanisms->apply($model, $data['mechanisms'], $actor);
            }
            if ($model->installation_id !== $sourceInstallation) {
                $this->mechanisms->guardInstallation($model);
            }
            $after = $model->toArray();
            if (array_key_exists('mechanisms', $data)) {
                $after['mechanisms'] = app(MechanismFacts::class)->read($model->id);
            }
            if (array_key_exists('inspection_categories', $data)) {
                $after['inspection_categories'] = $this->categories->read($model);
            }
            if (array_key_exists('visual_inspections', $data)) {
                $after['visual_inspections'] = $this->visual->read($model);
            }
            if (array_key_exists('operating_scopes', $data)) {
                $after['operating_scopes'] = $this->scopes->read($model);
            }
            if ($flowChange) {
                $after['flows'] = $this->flows->read($model);
            }
            if (array_key_exists('thickness', $data)) {
                $after['thickness'] = $this->thickness->read($model);
            }
            $this->writes->event('equipment', $model, 'updated', $actor, $before, $after);

            return $model->load('installation.parent.parent.parent');
        });
    }

    private function applicability(string $profileCode, array $passport, array $qualifiers = []): void
    {
        if (($passport['relief_valve_dual'] ?? null) === 'да' && ($passport['relief_valve_present'] ?? null) !== 'да') {
            throw ValidationException::withMessages(['passport.relief_valve_dual' => 'Для сдвоенного ППК сначала укажите наличие ППК.']);
        }
        $group = config('equipment.profiles')[$profileCode]['g'];
        foreach ($passport as $key => $value) {
            $allowed = config('equipment.passport')[$key]['groups'] ?? null;
            if ($allowed && $value !== null && ! in_array($group, $allowed, true)) {
                throw ValidationException::withMessages(['passport.'.$key => 'Поле не применяется к выбранному профилю оборудования.']);
            }
        }
        foreach ($qualifiers as $key => $qualifier) {
            $allowed = config('equipment.passport')[$key]['groups'] ?? null;
            if ($allowed && ! in_array($group, $allowed, true)) {
                throw ValidationException::withMessages(['passport_qualifiers.'.$key => 'Поле не применяется к выбранному профилю оборудования.']);
            }
            if (isset($qualifier['state']) && isset($passport[$key])) {
                throw ValidationException::withMessages(['passport_qualifiers.'.$key => 'Для неизвестного, отсутствующего или неприменимого значения очистите поле паспорта.']);
            }
            if (($qualifier['precision'] ?? null) === 'year' && isset($passport[$key]) && ! str_ends_with($passport[$key], '-01-01')) {
                throw ValidationException::withMessages(['passport_qualifiers.'.$key => 'При известном только годе дата хранится как 01.01 указанного года.']);
            }
        }
    }
}
