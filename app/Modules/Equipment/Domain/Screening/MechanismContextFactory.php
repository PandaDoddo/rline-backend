<?php

namespace App\Modules\Equipment\Domain\Screening;

use App\Modules\Equipment\Domain\ComponentParameterScope;
use App\Modules\Equipment\Domain\ComponentTree;
use App\Modules\Shared\Domain\ContentHash;

/** One indexed context per card; selected streams never become implicit physical exposure. */
final class MechanismContextFactory
{
    private ComponentTree $tree;

    private array $contacts;

    private array $effective = [];

    private array $scopes = [];

    private array $contactCache = [];

    private array $visual;

    public function __construct(array $components, private readonly array $passport, array $scopes, array $flows,
        private readonly array $flowCards, private readonly array $materials, private readonly array $settings,
        private readonly array $componentInputs, array $visual)
    {
        $this->tree = new ComponentTree($components);
        $this->contacts = array_column($flows['contacts'] ?? [], null, 'id');
        foreach ($flows['effective'] ?? [] as $entry) {
            $this->effective[$entry['component_id']][$entry['side']] = $entry;
        }
        foreach ($scopes as $scope) {
            $this->scopes[$scope['kind'].':'.($scope['component_id'] ?? '')] = $scope;
        }
        $this->visual = array_column($visual['elements'] ?? [], null, 'component_id');
    }

    public function element(string $id, bool $pipeline): array
    {
        $root = $this->tree->root($id);
        $scope = ComponentParameterScope::resolve($this->tree, $id)['key'];
        $values = $scope === 'vessel' ? $this->passport : (array) ($this->scopes[$scope]['values'] ?? []);
        $materialName = $this->tree->value($id, 'material');
        $material = is_string($materialName) ? ($this->materials[trim($materialName)] ?? null) : null;
        $materialValues = $material && ($material['status'] ?? 'active') === 'active' ? $material['values'] : null;
        $inside = $this->contact($id, 'internal');
        $outside = $this->contact($id, 'external');
        $externalEnvironment = array_values(array_filter($outside['all'], fn ($flow) => $flow['category'] === 'external'));
        $usableEnvironment = array_values(array_filter($externalEnvironment, fn ($flow) => $flow['usable']));
        $outsideStale = $outside['missing'] || count(array_filter($outside['all'], fn ($flow) => ! $flow['usable'])) > 0;
        $externalProcess = $outsideStale ? null : (count($outside['process']) > 0 && ! $externalEnvironment);
        $soil = null;
        if (! $outsideStale) {
            if (array_filter($usableEnvironment, fn ($flow) => $flow['medium_kind'] === 'soil')) {
                $soil = true;
            } elseif ($usableEnvironment) {
                $soil = false;
            } elseif (($values['ground_location'] ?? null) !== null) {
                $soil = match ($values['ground_location']) {
                    'подземное','частично подземное' => true,'надземное' => false,default => null
                };
            }
        }
        $meta = $this->visual[$id] ?? [];
        $insulated = ($meta['insulation_requires_review'] ?? false) ? null : ($meta['insulated'] ?? null);
        if ($insulated === null && ! ($meta['insulation_requires_review'] ?? false)) {
            $insulated = match ($values['insulation_present'] ?? null) {
                'присутствует' => true,'отсутствует' => false,default => null
            };
        }
        $tank = $this->inherited($id, 'tank_floor');
        $type = $this->tree->value($id, 'type_code');
        if ($tank === null && $type !== null && ! in_array($type, ['head', 'cap', 'element'], true)) {
            $tank = false;
        }
        $parameters = [];
        foreach (['temperature_max_c', 'pressure_operating_mpa', 'temperature_min_c', 'temperature_mdmt_c', 'temperature_upset_c', 'temperature_shutdown_c'] as $key) {
            $parameters[$key] = $this->number($values[$key] ?? null);
        }
        // MAT is derived from this element's resolved grade; no invisible passport scalar is invented.
        $parameters['temperature_mat_c'] = $this->number($materialValues['matMin'] ?? null);
        $context = ['material' => $materialValues === null ? null : array_intersect_key($materialValues, array_flip(['kind', 'subtype', 'rbi_group', 'rbi_crCO2', 'rbi_cr885'])),
            'parameters' => $parameters, 'insulated' => $insulated, 'external_process' => $externalProcess, 'external_source_usable' => ! $outsideStale, 'soil_contact' => $soil, 'tank_floor' => $tank, 'pipeline' => $pipeline,
            'vibration_present' => $this->settings['vibration_present'] ?? null, 'vibration_excluded' => $this->inherited($id, 'vibration_excluded'),
            'hydrogen_partial_pressure_mpa' => $this->settings['hydrogen_partial_pressure_mpa'] ?? null,
            'references' => ['scope' => $scope, 'material_id' => $material['id'] ?? null, 'material_version' => $material['version'] ?? null,
                'internal_contact' => $inside['id'], 'external_contact' => $outside['id'], 'insulation_contact' => $this->effective[$id]['insulation']['contact_id'] ?? null,
                'insulation_source' => $meta['insulation_source'] ?? 'unknown']];
        $key = ContentHash::of([$context, $inside['signature'], $outside['signature']]);

        $notes = [];
        foreach ($this->tree->lineage($id) as $owner) {
            $input = $this->componentInputs[$owner['id']] ?? [];
            if (($input['vibration_notes'] ?? null) !== null || ($input['tank_floor_notes'] ?? null) !== null) {
                $notes[] = ['component_id' => $owner['id'], 'vibration_notes' => $input['vibration_notes'] ?? null, 'tank_floor_notes' => $input['tank_floor_notes'] ?? null];
            }
        }

        return ['key' => $key, 'context' => $context, 'internal' => $inside['process'], 'external' => $outside['process'], 'notes' => $notes,
            'material_ref' => ['id' => $material['id'] ?? null, 'name' => $materialName, 'version' => $material['version'] ?? null, 'resolved' => $materialValues !== null],
            'active' => $this->tree->active($id), 'scope_id' => $root['is_coil'] ? $root['id'] : 'vessel', 'type_code' => $type];
    }

    private function inherited(string $id, string $key): mixed
    {
        foreach ($this->tree->lineage($id) as $component) {
            if (($this->componentInputs[$component['id']][$key] ?? null) !== null) {
                return $this->componentInputs[$component['id']][$key];
            }
        }

        return null;
    }

    private function contact(string $component, string $side): array
    {
        $effective = $this->effective[$component][$side] ?? [];
        $id = $effective['contact_id'] ?? null;
        if ($id !== null && isset($this->contactCache[$id])) {
            return $this->contactCache[$id];
        }
        $contact = $id === null ? null : ($this->contacts[$id] ?? null);
        $all = [];
        $process = [];
        $missing = [];
        if (($effective['mode'] ?? null) === 'assign' && $contact) {
            foreach ($contact['flows'] as $link) {
                $card = $this->flowCards[$link['flow_id']] ?? null;
                if (! $card) {
                    $missing[] = $link['flow_id'];

                    continue;
                }
                $flow = [...$card, 'usable' => ($card['status'] ?? null) === 'active' && ($link['accepted_version'] ?? null) === $card['version']];
                $all[$flow['id']] = $flow;
                if (in_array($flow['category'], ['main', 'alternative', 'auxiliary'], true) && $flow['medium_kind'] === 'fluid') {
                    $process[$flow['id']] = $flow;
                }
            }
        }
        ksort($all, SORT_STRING);
        ksort($process, SORT_STRING);
        $result = ['id' => $id, 'all' => array_values($all), 'process' => array_values($process), 'missing' => (bool) $missing, 'signature' => ContentHash::of(['mode' => $effective['mode'] ?? 'unknown', 'flows' => $all, 'missing' => $missing])];
        if ($id !== null) {
            $this->contactCache[$id] = $result;
        }

        return $result;
    }

    private function number(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(["\u{00a0}", "\u{202f}", ' ', ',', '−'], ['', '', '', '.', '-'], trim($value));
        }

        return is_numeric($value) && ! is_bool($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
