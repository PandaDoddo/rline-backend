<?php

namespace App\Modules\Equipment\Infrastructure;

use App\Modules\Equipment\Domain\Screening\ComponentScreeningSchema;
use App\Modules\Equipment\Models\ComponentMechanismInputs;
use App\Modules\Equipment\Models\ComponentMechanismOverride;
use App\Modules\Equipment\Models\ComponentMechanismReview;
use App\Modules\Equipment\Models\EquipmentMechanismInputs;
use App\Modules\Equipment\Models\MechanismQuestionNote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Typed facts only. This repository never stores calculated mechanism states. */
final class MechanismFacts
{
    public const ROOT_FIELDS = ['vibration_present', 'hydrogen_partial_pressure_mpa', 'vibration_notes', 'hydrogen_notes'];

    public const COMPONENT_FIELDS = ['vibration_excluded', 'tank_floor', 'vibration_notes', 'tank_floor_notes'];

    public function read(string $equipment): array
    {
        $input = EquipmentMechanismInputs::find($equipment);

        return ['schema_version' => ComponentScreeningSchema::VERSION, 'inputs' => $this->values($input, self::ROOT_FIELDS),
            'component_inputs' => ComponentMechanismInputs::where('equipment_id', $equipment)->orderBy('component_id')->get()->map(fn ($row) => ['component_id' => $row->component_id, ...$this->values($row, self::COMPONENT_FIELDS)])->all(),
            'overrides' => ComponentMechanismOverride::where('equipment_id', $equipment)->orderBy('component_id')->orderBy('mechanism_code')->orderBy('side')->get()->map(fn ($row) => [
                'component_id' => $row->component_id, 'mechanism_code' => $row->mechanism_code, 'side' => $row->side, 'state' => $row->state, 'reason' => $row->reason, 'source_signature' => $row->source_signature, 'updated_by' => $row->updated_by, 'updated_at' => $row->updated_at?->toISOString()])->all(),
            'question_notes' => MechanismQuestionNote::where('equipment_id', $equipment)->orderBy('component_id')->orderBy('question_id')->orderBy('side')->orderBy('flow_id')->get()->map(fn ($row) => [
                'component_id' => $row->component_id, 'question_id' => $row->question_id, 'side' => $row->side, 'flow_id' => $row->flow_id, 'note' => $row->note, 'updated_by' => $row->updated_by, 'updated_at' => $row->updated_at?->toISOString()])->all(),
            'reviews' => ComponentMechanismReview::where('equipment_id', $equipment)->orderBy('component_id')->get()->map(fn ($row) => [
                'component_id' => $row->component_id, 'input_signature' => $row->input_signature, 'verified_by' => $row->verified_by, 'verified_at' => $row->verified_at?->toISOString()])->all()];
    }

    public function root(string $equipment, array $values, int $actor): void
    {
        $this->transaction();
        $model = EquipmentMechanismInputs::find($equipment) ?? new EquipmentMechanismInputs;
        $this->nullable($model, ['equipment_id' => $equipment], $values, self::ROOT_FIELDS, $actor);
    }

    public function component(string $equipment, string $component, array $values, int $actor): void
    {
        $this->transaction();
        $model = ComponentMechanismInputs::where('equipment_id', $equipment)->where('component_id', $component)->first() ?? new ComponentMechanismInputs;
        $this->nullable($model, ['equipment_id' => $equipment, 'component_id' => $component], $values, self::COMPONENT_FIELDS, $actor);
    }

    public function override(string $equipment, array $entry, int $actor): void
    {
        $this->transaction();
        $identity = ['equipment_id' => $equipment, 'component_id' => $entry['component_id'], 'mechanism_code' => $entry['mechanism_code'], 'side' => $entry['side']];
        $model = ComponentMechanismOverride::where($identity)->first();
        if ($entry['state'] === null) {
            $model?->delete();

            return;
        }
        $model ??= new ComponentMechanismOverride;
        if (! $model->exists) {
            $model->forceFill(['created_by' => $actor]);
        }
        $model->forceFill([...$identity, 'state' => $entry['state'], 'reason' => $entry['reason'], 'source_signature' => $entry['source_signature'], 'updated_by' => $actor])->save();
    }

    public function note(string $equipment, array $entry, int $actor): void
    {
        $this->transaction();
        $identity = ['equipment_id' => $equipment, 'component_id' => $entry['component_id'], 'question_id' => $entry['question_id'], 'side' => $entry['side'], 'flow_id' => $entry['flow_id']];
        $model = MechanismQuestionNote::where($identity)->first();
        if ($entry['note'] === null) {
            $model?->delete();

            return;
        }
        $model ??= new MechanismQuestionNote;
        if (! $model->exists) {
            $model->forceFill(['created_by' => $actor]);
        }
        $model->forceFill([...$identity, 'note' => $entry['note'], 'updated_by' => $actor])->save();
    }

    public function review(string $equipment, string $component, ?string $signature, int $actor): void
    {
        $this->transaction();
        $db = DB::connection('tenant');
        $db->table('equipment_mechanism_reviews')->where('equipment_id', $equipment)->where('component_id', $component)->delete();
        if ($signature !== null) {
            $db->table('equipment_mechanism_reviews')->insert(['equipment_id' => $equipment, 'component_id' => $component, 'input_signature' => $signature, 'verified_by' => $actor, 'verified_at' => now()]);
        }
    }

    private function nullable(Model $model, array $identity, array $input, array $fields, int $actor): void
    {
        $model->forceFill([...$identity, ...array_intersect_key($input, array_flip($fields))]);
        if (! array_filter($this->values($model, $fields), fn ($value) => $value !== null)) {
            if ($model->exists) {
                $model->delete();
            }

            return;
        }
        if (! $model->exists) {
            $model->forceFill(['created_by' => $actor]);
        }
        $model->forceFill(['updated_by' => $actor])->save();
    }

    private function values(?Model $model, array $fields): array
    {
        return array_combine($fields, array_map(fn ($field) => $model?->getAttribute($field), $fields));
    }

    private function transaction(): void
    {
        if (DB::connection('tenant')->transactionLevel() === 0) {
            throw new LogicException('Mechanism facts require an equipment aggregate transaction.');
        }
    }
}
