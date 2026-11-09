<?php

namespace App\Modules\Shared\Http\Resources;

use App\Modules\Shared\Models\DomainEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin DomainEvent
 *
 * @property-read array<int,array{key:string,label:string,from:string|null,to:string|null}> $changes
 * @property-read string|null $actor_name
 */
class AuditEventResource extends JsonResource
{
    /** @return array{id:int,action:string,actor_id:int,actor_name:string|null,created_at:string,changes:array<int,array{key:string,label:string,from:string|null,to:string|null}>} */
    public function toArray(Request $request): array
    {
        return ['id' => (int) $this->id, 'action' => (string) $this->action, 'actor_id' => (int) $this->actor_id, 'actor_name' => $this->actor_name === null ? null : (string) $this->actor_name,
            'created_at' => Carbon::parse($this->created_at)->toIso8601String(), 'changes' => $this->changes];
    }
}
