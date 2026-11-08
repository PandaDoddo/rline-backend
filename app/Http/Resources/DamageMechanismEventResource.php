<?php

namespace App\Http\Resources;

use App\Models\DomainEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin DomainEvent
 *
 * @property-read int $id
 * @property-read string $action
 * @property-read int $actor_id
 * @property-read string $created_at
 * @property-read array $changes
 */
class DamageMechanismEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (int) $this->id, 'action' => (string) $this->action, 'actor_id' => (int) $this->actor_id, 'actor_name' => $this->actor_name === null ? null : (string) $this->actor_name,
            'created_at' => Carbon::parse($this->created_at)->toIso8601String(), 'changes' => $this->changes];
    }
}
