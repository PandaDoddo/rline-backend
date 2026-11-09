<?php

namespace App\Modules\Rbi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Fluent;

/** @property-read Fluent $resource */
class ManagementSchemaResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{version:string,sections:array<int,array{id:string,title:string,weight:float,position:int,questions:array<int,array{id:string,number:string,subnumber:string,text:string,maximum:float,kind:'heading'|'boolean'|'score'|'multiple',position:int,guidance:string|null,options:array<int,array{index:int,label:string,points:float,exclusive:bool}>}>}>}
     */
    public function toArray(Request $request): array
    {
        return $this->resource->all();
    }
}
