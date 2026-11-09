<?php

namespace App\Modules\Catalogs\Http\Requests;

use App\Modules\Catalogs\Domain\SteelGradeValues;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SteelGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(app(TenantContext::class)->membership($this->user()->id)?->role, ['owner', 'admin'], true);
    }

    protected function prepareForValidation(): void
    {
        if (is_array($values = $this->input('values'))) {
            foreach ($values as $key => $value) {
                $values[$key] = SteelGradeValues::normalize($key, $value);
            }
            $this->merge(['values' => $values]);
        }
    }

    public function rules(): array
    {
        $fields = config('steel-grades.fields');
        $keys = array_keys($fields);
        $rules = ['values' => 'required|array:'.implode(',', $keys), 'origin' => 'prohibited', 'base_values' => 'prohibited', 'base_version' => 'prohibited', 'checks' => 'prohibited', 'status' => 'prohibited', 'version' => 'prohibited',
            'sources' => 'sometimes|array:'.implode(',', $keys), 'sources.*' => 'required|array:citation,conditions', 'sources.*.citation' => 'nullable|string|max:4000', 'sources.*.conditions' => 'nullable|string|max:4000'];
        foreach ($fields as $key => $field) {
            if ($field['readonly'] ?? false) {
                $rules['values.'.$key] = 'missing';

                continue;
            }
            $required = $key === 'name' && $this->isMethod('POST') ? 'required' : 'sometimes';
            if ($field['type'] === 'number') {
                $rules['values.'.$key] = [$required, 'nullable', function ($attribute, $value, $fail) use ($field) {
                    if (in_array($value, config('steel-grades.missing_values'), true)) {
                        return;
                    }
                    if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < $field['min'] || $value > $field['max']) {
                        $fail($field['label'].': укажите число в допустимом диапазоне или отсутствие данных.');
                    }
                }];
            } elseif (in_array($field['type'], ['composition', 'stress'], true)) {
                $rules['values.'.$key] = 'sometimes|nullable|array';
            } else {
                $rules['values.'.$key] = [$required, $key === 'name' ? 'filled' : 'nullable', 'string', 'max:'.$field['max']];
            }
        }
        $rules = array_replace($rules, ['values.composition' => 'sometimes|nullable|array|max:118', 'values.composition.*' => 'required|array:el,min,max', 'values.composition.*.el' => ['required', 'string', Rule::in(explode(' ', 'H He Li Be B C N O F Ne Na Mg Al Si P S Cl Ar K Ca Sc Ti V Cr Mn Fe Co Ni Cu Zn Ga Ge As Se Br Kr Rb Sr Y Zr Nb Mo Tc Ru Rh Pd Ag Cd In Sn Sb Te I Xe Cs Ba La Ce Pr Nd Pm Sm Eu Gd Tb Dy Ho Er Tm Yb Lu Hf Ta W Re Os Ir Pt Au Hg Tl Pb Bi Po At Rn Fr Ra Ac Th Pa U Np Pu Am Cm Bk Cf Es Fm Md No Lr Rf Db Sg Bh Hs Mt Ds Rg Cn Nh Fl Mc Lv Ts Og')), 'distinct:strict'], 'values.composition.*.min' => 'nullable|numeric|min:0|max:100', 'values.composition.*.max' => 'nullable|numeric|min:0|max:100', 'values.stress' => 'sometimes|nullable|array:columns,rows',
            'values.stress.columns' => 'required_with:values.stress|array|min:1|max:12', 'values.stress.columns.*' => 'required|array:id,label', 'values.stress.columns.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,29}$/', 'not_in:t,note,constructor,prototype,__proto__', 'distinct:strict'], 'values.stress.columns.*.label' => 'required|string|max:255', 'values.stress.rows' => 'sometimes|array|max:1000', 'values.stress.rows.*' => 'required|array', 'values.stress.rows.*.t' => 'required|numeric|min:-273.15|max:3000|distinct:strict', 'values.stress.rows.*.note' => 'sometimes|nullable|string|max:2000']);

        return $rules;
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (array_sum(array_column($this->input('values.composition', []) ?? [], 'min')) > 100.000001) {
                $validator->errors()->add('values.composition', 'Сумма минимальных массовых долей не может превышать 100 %.');
            }
            foreach ($this->input('values.composition', []) ?? [] as $index => $row) {
                if (isset($row['min'], $row['max']) && $row['min'] > $row['max']) {
                    $validator->errors()->add('values.composition.'.$index.'.max', 'Максимум не может быть меньше минимума.');
                }
            }
            $stress = $this->input('values.stress');
            if (! is_array($stress)) {
                return;
            }
            if (! isset($stress['columns']) || ! array_key_exists('rows', $stress)) {
                $validator->errors()->add('values.stress', 'Укажите источники и массив точек (он может быть пустым).');

                return;
            }
            $ids = array_column($stress['columns'], 'id');
            $temperatures = [];
            foreach ($stress['rows'] as $index => $row) {
                $temperature = (string) (float) $row['t'];
                if (isset($temperatures[$temperature])) {
                    $validator->errors()->add('values.stress.rows.'.$index.'.t', 'Температуры не должны повторяться.');
                }
                $temperatures[$temperature] = true;
                foreach ($row as $key => $value) {
                    if (in_array($key, ['t', 'note'], true)) {
                        continue;
                    }
                    if (! in_array($key, $ids, true)) {
                        $validator->errors()->add('values.stress.rows.'.$index.'.'.$key, 'Неизвестный источник напряжений.');

                        continue;
                    }
                    if ($value !== null && ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > 100000)) {
                        $validator->errors()->add('values.stress.rows.'.$index.'.'.$key, 'Укажите неотрицательное напряжение в МПа или пустое значение.');
                    }
                }
            }
        }];
    }
}
