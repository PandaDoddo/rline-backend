<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(app(TenantContext::class)->membership($this->user()->id)?->role, ['owner', 'admin'], true);
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'code'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key)) ?: null]);
            }
        }
        $passport = $this->input('passport');
        if (is_array($passport)) {
            foreach (['capP', 'capF', 'area', 'stShift', 'stAll'] as $key) {
                if (isset($passport[$key]) && is_string($passport[$key])) {
                    $passport[$key] = str_replace([' ', ','], ['', '.'], trim($passport[$key]));
                    if ($passport[$key] === '') {
                        $passport[$key] = null;
                    } elseif (is_numeric($passport[$key])) {
                        $passport[$key] += 0;
                    }
                }
            }
            $this->merge(['passport' => $passport]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|min:2|max:255',
            'code' => ['sometimes', 'nullable', 'string', 'max:80', Rule::unique('tenant.installations', 'code')->ignore($this->route('installation'))],
            'parent_id' => ['required', 'uuid', Rule::exists('tenant.facility_nodes', 'id')->where('kind', 'production')],
            'version' => 'prohibited',
            'passport' => 'sometimes|array:full,purpose,year,capP,capF,capU,designer,licensor,opoReg,opoCls,cat,atm,area,stShift,stAll,head,mech',
            'passport.purpose' => 'sometimes|nullable|string|max:5000',
            'passport.capU' => 'nullable|required_with:passport.capP,passport.capF|string|max:80',
            'passport.opoCls' => 'sometimes|nullable|in:I,II,III,IV',
            'passport.cat' => 'sometimes|nullable|in:А,Б,В1,В2,В3,В4,Г,Д,Ан,Бн,Вн,Гн,Дн',
            'passport.atm' => 'sometimes|nullable|in:тяжелые,умеренные,мягкие,сухие',
        ];
        foreach (['full', 'year', 'designer', 'licensor', 'opoReg', 'head', 'mech'] as $key) {
            $rules['passport.'.$key] = 'sometimes|nullable|string|max:255';
        }
        foreach (['capP', 'capF', 'area'] as $key) {
            $rules['passport.'.$key] = 'sometimes|nullable|numeric|min:0|max:999999999999';
        }
        foreach (['stShift', 'stAll'] as $key) {
            $rules['passport.'.$key] = 'sometimes|nullable|integer|min:0|max:10000000';
        }

        return $rules;
    }
}
