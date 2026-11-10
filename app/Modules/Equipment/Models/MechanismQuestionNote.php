<?php

namespace App\Modules\Equipment\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MechanismQuestionNote extends TenantModel
{
    use HasUuids;

    protected $table = 'equipment_mechanism_question_notes';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';
}
