<?php

namespace App\Modules\Rbi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Fluent;

/** @property-read Fluent $resource */
class ManagementResource extends JsonResource
{
    /**
     * @return array{
     * installation_id:string,version:int,schema_version:string,
     * details:array{assessed_on:string|null,auditor_name:string|null,notes:string|null},
     * answers:array<int,array{question_id:string,state:'answered'|'unanswered'|'not_applicable',value:bool|float|array<int,int>|null,comment:string|null}>,
     * assessment:array{method:string,ready:bool,missing:array<int,string>,answered:int,total:int,score:float|null,provisional_score:float,fms_pof:float|null,fms_checklist_legacy:float|null,factor_policy:string,
     * sections:array<int,array{id:string,t:string,w:float,mx:float,f:float,sc:float,ws:float,ans:int,tot:int}>}
     * }
     */
    public function toArray(Request $request): array
    {
        return $this->resource->all();
    }
}
