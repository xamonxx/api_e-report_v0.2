<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyStatusHistory extends Model
{
    protected $fillable = [
        'survey_id',
        'from_state',
        'to_state',
        'changed_by',
    ];

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
