<?php

namespace App\Modules\Installations\Application;

use App\Modules\Equipment\Models\Equipment;
use App\Modules\Installations\Models\FacilityNode;
use App\Modules\Installations\Models\Installation;
use App\Modules\Installations\Models\InstallationMaintenanceRecord;
use App\Modules\ProcessFlows\Models\ProcessFlowNode;
use App\Modules\Shared\Infrastructure\TenantWrites;
use Illuminate\Validation\ValidationException;

class FacilityLifecycle
{
    public function __construct(private TenantWrites $writes) {}

    public function dependencies(Installation $installation): array
    {
        return [
            'equipment' => Equipment::where('installation_id', $installation->id)->count(),
            'active_equipment' => Equipment::where('installation_id', $installation->id)->where('status', 'active')->count(),
            'flow_nodes' => ProcessFlowNode::where('installation_id', $installation->id)->count(),
            'active_flow_nodes' => ProcessFlowNode::where('installation_id', $installation->id)->where('status', 'active')->count(),
            'maintenance_records' => InstallationMaintenanceRecord::withTrashed()->where('installation_id', $installation->id)->count(),
        ];
    }

    public function installation(string $id, array $data, int $actor, bool $delete = false): ?Installation
    {
        return $this->writes->transaction(function () use ($id, $data, $actor, $delete) {
            $installation = Installation::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->writes->version($installation, $data['version']);
            $before = $installation->toArray();
            $dependencies = $this->dependencies($installation);
            if ($delete) {
                if ($dependencies['equipment'] || $dependencies['flow_nodes'] || $dependencies['maintenance_records']) {
                    throw ValidationException::withMessages(['installation' => 'Установка имеет оборудование, потоки или историю обслуживания. Используйте архив; связанные данные не удаляются.']);
                }
                $this->writes->event('installation', $installation, 'deleted', $actor, $before, null);
                $installation->delete();

                return null;
            }
            $restore = ($data['action'] ?? 'archive') === 'restore';
            abort_if($installation->status === ($restore ? 'active' : 'archived'), 409, 'Состояние установки уже изменено.');
            if ($restore && ! FacilityNode::whereKey($installation->parent_id)->where('status', 'active')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['parent_id' => 'Сначала восстановите вышестоящее производство.']);
            }
            $manifest = $installation->archive_manifest ?? ['equipment' => [], 'flows' => []];
            if (! $restore) {
                if (($dependencies['active_equipment'] || $dependencies['active_flow_nodes']) && ! ($data['cascade'] ?? false)) {
                    throw ValidationException::withMessages(['cascade' => 'Подтвердите архивирование активного оборудования и потоков установки.']);
                }
                $manifest = ['equipment' => [], 'flows' => []];
                foreach (['equipment' => Equipment::class, 'flows' => ProcessFlowNode::class] as $key => $class) {
                    foreach ($class::where('installation_id', $id)->where('status', 'active')->lockForUpdate()->get() as $child) {
                        $old = $child->toArray();
                        $child->forceFill(['status' => 'archived', 'version' => $child->version + 1, 'updated_by' => $actor])->save();
                        $manifest[$key][$child->id] = $child->version;
                        $this->writes->event($key === 'equipment' ? 'equipment' : 'process_flow', $child, 'archived_with_installation', $actor, $old, $child->toArray());
                    }
                }
            } elseif ($data['cascade'] ?? false) {
                foreach (['equipment' => Equipment::class, 'flows' => ProcessFlowNode::class] as $key => $class) {
                    foreach ($class::where('installation_id', $id)->whereIn('id', array_keys($manifest[$key] ?? []))->lockForUpdate()->get() as $child) {
                        abort_if($child->status !== 'archived' || $child->version !== $manifest[$key][$child->id], 409, 'Связанная запись изменена после архивирования. Восстановите её отдельно.');
                        $old = $child->toArray();
                        $child->forceFill(['status' => 'active', 'version' => $child->version + 1, 'updated_by' => $actor])->save();
                        $this->writes->event($key === 'equipment' ? 'equipment' : 'process_flow', $child, 'restored_with_installation', $actor, $old, $child->toArray());
                    }
                }
            }
            $installation->forceFill(['status' => $restore ? 'active' : 'archived', 'archive_manifest' => $restore ? null : $manifest,
                'flow_structure_version' => $installation->flow_structure_version + 1, 'version' => $installation->version + 1, 'updated_by' => $actor])->save();
            $this->writes->event('installation', $installation, $restore ? 'restored' : 'archived', $actor, $before, $installation->toArray());

            return $installation;
        });
    }

    public function node(string $id, array $data, int $actor, bool $delete = false): ?FacilityNode
    {
        return $this->writes->transaction(function () use ($id, $data, $actor, $delete) {
            $node = FacilityNode::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->writes->version($node, $data['version']);
            $before = $node->toArray();
            $restore = ($data['action'] ?? 'archive') === 'restore';
            $children = FacilityNode::where('parent_id', $id);
            $installations = Installation::where('parent_id', $id);
            if ($delete ? $children->exists() || $installations->exists() : ! $restore && ($children->where('status', 'active')->exists() || $installations->where('status', 'active')->exists())) {
                throw ValidationException::withMessages(['node' => 'Сначала обработайте дочерние записи. Связи и история не удаляются каскадно.']);
            }
            if ($delete) {
                $this->writes->event('facility_node', $node, 'deleted', $actor, $before, null);
                $node->delete();

                return null;
            }
            abort_if($node->status === ($restore ? 'active' : 'archived'), 409, 'Состояние узла уже изменено.');
            if ($restore && $node->parent_id && ! FacilityNode::whereKey($node->parent_id)->where('status', 'active')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['parent_id' => 'Сначала восстановите родительский узел.']);
            }
            $node->forceFill(['status' => $restore ? 'active' : 'archived', 'version' => $node->version + 1, 'updated_by' => $actor])->save();
            $this->writes->event('facility_node', $node, $restore ? 'restored' : 'archived', $actor, $before, $node->toArray());

            return $node;
        });
    }
}
