<?php

namespace App\Modules\Equipment\Http\Resources;

use App\Modules\Equipment\Models\ComponentHistoryEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ComponentHistoryEntry */
class ComponentHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'kind' => (string) $this->kind, 'occurred_on' => $this->occurred_on?->toDateString(),
            'actor_name' => $this->actor_name, 'description' => (string) $this->description, 'source_reference' => $this->source_reference,
            'recorded_by' => (int) $this->recorded_by, 'recorded_at' => $this->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }
}
