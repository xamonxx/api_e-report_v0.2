<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyReschedule extends Model
{
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_MANAGER = 'manager';

    public const FIELD_REQUESTED = 'requested';
    public const FIELD_SCHEDULED = 'scheduled';

    protected $fillable = [
        'survey_id',
        'source',
        'field',
        'old_at',
        'new_at',
        'changed_by',
        'changed_by_role',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'old_at' => 'datetime',
            'new_at' => 'datetime',
        ];
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
