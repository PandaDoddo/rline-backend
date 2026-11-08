<?php

namespace App\Http\Resources;

use App\Models\ProcessFlowCalculation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProcessFlowCalculation */
class ProcessFlowCalculationSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'signature' => (string) $this->signature, 'engine_version' => (string) $this->engine_version,
            'created_by' => (int) $this->created_by, 'created_at' => $this->created_at->toIso8601String()];
    }
}
