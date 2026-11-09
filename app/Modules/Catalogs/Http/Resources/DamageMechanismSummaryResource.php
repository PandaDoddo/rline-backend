<?php

namespace App\Modules\Catalogs\Http\Resources;

use App\Modules\Catalogs\Models\DamageMechanism;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DamageMechanism */
class DamageMechanismSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'legacy_code' => (string) $this->legacy_code, 'name' => (string) $this->label, 'english_name' => $this->summary_english_name, 'paragraph' => $this->summary_paragraph, 'designation' => $this->summary_designation, 'kind' => $this->summary_kind, 'df_mapping' => $this->summary_df_mapping, 'material_groups' => $this->summary_material_groups ?? [], 'status' => $this->status, 'version' => (int) $this->version];
    }
}
