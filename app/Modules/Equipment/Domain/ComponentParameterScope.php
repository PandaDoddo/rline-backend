<?php

namespace App\Modules\Equipment\Domain;

/** Parameter ownership is independent of which components share a physical medium. */
final class ComponentParameterScope
{
    public static function resolve(ComponentTree $tree, string $component): array
    {
        $root = $tree->root($component);
        if ($root['is_coil']) {
            return ['kind' => 'coil', 'component_id' => $root['id'], 'key' => 'coil:'.$root['id']];
        }
        if (($root['kind'] ?? null) === 'tube_bundle' || $tree->value($component, 'flow_space') === 'tube') {
            return ['kind' => 'tube', 'component_id' => null, 'key' => 'tube:'];
        }

        return ['kind' => 'vessel', 'component_id' => null, 'key' => 'vessel'];
    }
}
