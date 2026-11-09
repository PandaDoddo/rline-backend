<?php

namespace App\Modules\Equipment\Domain;

use InvalidArgumentException;

/** Indexed ancestry shared by calculations and assignment inheritance. */
final class ComponentTree
{
    private array $byId;

    private array $paths = [];

    public function __construct(array $components)
    {
        $this->byId = array_column($components, null, 'id');
        if (count($this->byId) !== count($components)) {
            throw new InvalidArgumentException('Компонент повторяется в структуре.');
        }
    }

    /** Nearest element first, root last. */
    public function lineage(string $id): array
    {
        if (isset($this->paths[$id])) {
            return $this->paths[$id];
        }
        $path = [];
        $seen = [];
        $next = $id;
        while ($next !== null) {
            if (isset($seen[$next]) || count($path) >= 8) {
                throw new InvalidArgumentException('Цикл или глубина структуры больше 8 уровней.');
            }
            $row = $this->byId[$next] ?? throw new InvalidArgumentException('Родитель должен принадлежать этой ЕО.');
            $seen[$next] = true;
            $path[] = $row;
            $next = $row['parent_id'];
        }

        return $this->paths[$id] = $path;
    }

    public function root(string $id): array
    {
        $path = $this->lineage($id);

        return $path[array_key_last($path)];
    }

    public function active(string $id): bool
    {
        foreach ($this->lineage($id) as $row) {
            if ($row['status'] !== 'active') {
                return false;
            }
        }

        return true;
    }

    public function value(string $id, string $field): mixed
    {
        foreach ($this->lineage($id) as $row) {
            if (isset($row[$field]) && $row[$field] !== '') {
                return $row[$field];
            }
        }

        return null;
    }
}
