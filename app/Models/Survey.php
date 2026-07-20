<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Survey extends Model
{
    use HasFactory, SoftDeletes;

    public const STATE_REQUESTED = 'requested';
    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_IN_PROGRESS = 'in_progress';
    public const STATE_COMPLETED = 'completed';
    public const STATE_CANCELLED = 'cancelled';

    public const STATES = [
        self::STATE_REQUESTED,
        self::STATE_SCHEDULED,
        self::STATE_IN_PROGRESS,
        self::STATE_COMPLETED,
        self::STATE_CANCELLED,
    ];

    protected $fillable = [
        'consultation_id',
        'account_id',
        'state',
        'requested_by',
        'requested_at',
        'requested_date',
        'requested_time',
        'requested_item',
        'surveyor_id',
        'assigned_by',
        'assigned_at',
        'scheduled_at',
        'actual_start_at',
        'actual_finish_at',
        'location_notes',
        'admin_notes',
        'manager_notes',
        'google_maps_url',
        'result_status_id',
        'result_notes',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'requested_date' => 'date',
            'assigned_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_finish_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // Relations
    public function consultation()
    {
        return $this->belongsTo(Consultation::class);
    }

    public function surveyor()
    {
        return $this->belongsTo(User::class, 'surveyor_id');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resultStatus()
    {
        return $this->belongsTo(SurveyStatus::class, 'result_status_id');
    }

    public function histories()
    {
        return $this->hasMany(SurveyStatusHistory::class)->oldest();
    }

    public function reschedules()
    {
        return $this->hasMany(SurveyReschedule::class)->latest();
    }

    public function activityLogs()
    {
        return $this->hasMany(SurveyActivityLog::class)->oldest();
    }

    /** Change state and write both transition history and the activity timeline. */
    public function transitionTo(string $toState, ?string $notes = null): void
    {
        $fromState = $this->state;
        $this->state = $toState;
        $this->save();

        if ($fromState !== $toState) {
            SurveyStatusHistory::create([
                'survey_id' => $this->id,
                'from_state' => $fromState,
                'to_state' => $toState,
                'changed_by' => auth()->id(),
            ]);
        }

        $user = auth()->user();
        $role = $user?->role;
        SurveyActivityLog::create([
            'survey_id' => $this->id,
            'consultation_id' => $this->consultation_id,
            'user_id' => $user?->id,
            'user_role' => $role instanceof \BackedEnum ? $role->value : $role,
            'action' => 'status_changed',
            'old_status' => $fromState,
            'new_status' => $toState,
            'notes' => $notes,
        ]);
    }
}
