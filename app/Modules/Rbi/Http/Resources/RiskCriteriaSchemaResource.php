<?php

namespace App\Modules\Rbi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Fluent;

/** @property-read Fluent $resource */
class RiskCriteriaSchemaResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{version:string,policy:string,fields:array<int,array{key:string,label:string,unit:string,default:float,nullable:bool,min:float,max:float,step?:float}>} */
    public function toArray(Request $request): array
    {
        return $this->resource->all();
    }
}
