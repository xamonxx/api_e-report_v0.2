<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Survey extends Model
{
    use Auditable, HasFactory, SoftDeletes;

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
        'schedule_revision',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'requested_date' => 'date:Y-m-d',
            'assigned_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_finish_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Jaga `active_key` supaya unique index "satu survey aktif per lead" selalu
     * konsisten tanpa perlu diingat di tiap controller.
     *
     * Berisi consultation_id selama survey aktif, NULL saat cancelled atau
     * soft-deleted - MySQL mengabaikan NULL pada unique index.
     */
    protected static function booted(): void
    {
        static::saving(function (self $survey) {
            $survey->active_key = $survey->state === self::STATE_CANCELLED
                ? null
                : $survey->consultation_id;
            $survey->schedule_slot_key = $survey->surveyor_id && $survey->scheduled_at
                && in_array($survey->state, [self::STATE_SCHEDULED, self::STATE_IN_PROGRESS], true)
                    ? $survey->surveyor_id.'|'.$survey->scheduled_at->format('Y-m-d H:i:s')
                    : null;
        });

        // Soft delete memakai query update langsung, tidak melewati `saving`.
        static::deleted(function (self $survey) {
            static::withTrashed()->whereKey($survey->getKey())->update([
                'active_key' => null,
                'schedule_slot_key' => null,
            ]);
        });

        static::restored(function (self $survey) {
            static::withTrashed()->whereKey($survey->getKey())->update([
                'active_key' => $survey->state === self::STATE_CANCELLED ? null : $survey->consultation_id,
                'schedule_slot_key' => $survey->surveyor_id && $survey->scheduled_at
                    && in_array($survey->state, [self::STATE_SCHEDULED, self::STATE_IN_PROGRESS], true)
                        ? $survey->surveyor_id.'|'.$survey->scheduled_at->format('Y-m-d H:i:s')
                        : null,
            ]);
        });
    }

    // Relations
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }
        if ($user->isSurveyTeam()) {
            if ($user->isSurveyor()) {
                return $query->where('surveys.surveyor_id', $user->id);
            }

            return $query;
        }
        if ($user->isAdmin()) {
            return $query->where('surveys.account_id', $user->activeAccountId())->where('surveys.requested_by', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function consultation()
    {
        return $this->belongsTo(Consultation::class);
    }

    public function surveyor()
    {
        return $this->belongsTo(User::class, 'surveyor_id')->withTrashed();
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by')->withTrashed();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    public function resultStatus()
    {
        return $this->belongsTo(SurveyStatus::class, 'result_status_id')->withTrashed();
    }

    public function histories()
    {
        return $this->hasMany(SurveyStatusHistory::class)->oldest();
    }

    public function reschedules()
    {
        return $this->hasMany(SurveyReschedule::class)->latest();
    }

    public function loanApprovals()
    {
        return $this->hasMany(SurveyLoanApproval::class);
    }

    public function reminderDeliveries()
    {
        return $this->hasMany(SurveyReminderDelivery::class);
    }

    /** True bila surveyor ini punya izin pinjam aktif untuk survey ini. */
    public function hasActiveLoanApprovalFor(int $surveyorId): bool
    {
        return $this->loanApprovals()->where('surveyor_id', $surveyorId)->active()->exists();
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

            // Sync consultation status category
            $consultation = $this->consultation;
            if ($consultation) {
                $targetStatusName = null;
                if ($toState === self::STATE_IN_PROGRESS) {
                    $targetStatusName = 'Sedang Survey';
                } elseif ($toState === self::STATE_COMPLETED) {
                    $targetStatusName = 'Selesai Survey';
                } elseif ($toState === self::STATE_CANCELLED) {
                    $consultation->loadMissing('statusCategory');
                    $currentStatusName = $consultation->statusCategory?->name;
                    if ($currentStatusName === 'Sedang Survey' || $currentStatusName === 'Selesai Survey') {
                        $targetStatusName = 'Request Survey';
                    }
                }

                if ($targetStatusName) {
                    $targetStatusId = StatusCategory::whereRaw('LOWER(name) = ?', [strtolower($targetStatusName)])->value('id');
                    if ($targetStatusId) {
                        $consultation->status_category_id = $targetStatusId;
                        $consultation->save();

                        // Invalidate cache
                        try {
                            $superAdmins = User::where('role', UserRole::SuperAdmin)->pluck('id');
                            foreach ($superAdmins as $adminId) {
                                Cache::forget("dashboard:super_admin:{$adminId}");
                            }
                        } catch (\Throwable $e) {
                        }
                        if ($consultation->account_id) {
                            Cache::forget("dashboard:admin:{$consultation->account_id}");
                        }
                        Cache::forever('analytics:last_updated', now()->timestamp);
                    }
                }
            }
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
