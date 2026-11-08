<?php

namespace App\Domain;

use App\Models\ProcessFlowNode;
use Illuminate\Validation\ValidationException;

class FlowHierarchy
{
    public function parent(string $installation, ?string $parent, string $category, ?string $id = null): void
    {
        $depth = 1;
        while ($parent !== null) {
            if ($parent === $id || ++$depth > 8) {
                throw ValidationException::withMessages(['parent_id' => 'Цикл или глубина дерева больше 8 уровней.']);
            }
            $node = ProcessFlowNode::whereKey($parent)->where('installation_id', $installation)->firstOrFail();
            if ($node->kind !== 'folder' || $node->category !== $category || $node->status !== 'active') {
                throw ValidationException::withMessages(['parent_id' => 'Родитель должен быть действующей группой той же категории и установки.']);
            }
            $parent = $node->parent_id;
        }
        if ($id !== null) {
            $height = 0;
            $ids = [$id];
            while ($ids !== []) {
                $ids = ProcessFlowNode::whereIn('parent_id', $ids)->pluck('id')->all();
                if ($ids !== [] && $depth + ++$height > 8) {
                    throw ValidationException::withMessages(['parent_id' => 'Перенос увеличит глубину дерева больше 8 уровней.']);
                }
            }
        }
    }
}
