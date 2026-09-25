<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Survey extends Model
{
    use HasFactory, SoftDeletes, Auditable;

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
        });

        // Soft delete memakai query update langsung, tidak melewati `saving`.
        static::deleted(function (self $survey) {
            static::withTrashed()->whereKey($survey->getKey())->update(['active_key' => null]);
        });

        static::restored(function (self $survey) {
            static::withTrashed()->whereKey($survey->getKey())->update([
                'active_key' => $survey->state === self::STATE_CANCELLED ? null : $survey->consultation_id,
            ]);
        });
    }

    // Relations
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, User $user): \Illuminate\Database\Eloquent\Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }
        if ($user->isSurveyTeam()) {
            if (! $user->hasSurveyTeam()) {
                return $query->whereRaw('1 = 0');
            }
            if ($user->isSurveyor()) {
                // Surveyor pinjaman (GACONG, lihat SurveyController) bisa
                // ditugaskan ke survey Team F walau timnya sendiri beda -
                // makanya Team F dibuka lewat kepemilikan (surveyor_id) tanpa
                // syarat tim. Tim lain (A-E) tetap wajib kecocokan tim DAN
                // kepemilikan sekaligus, KECUALI ada izin pinjam aktif
                // (SurveyLoanApproval) untuk pasangan survey+surveyor ini -
                // tanpa baris ini, surveyor yang dipinjam ke Team C/D lewat
                // persetujuan Super Admin tidak akan pernah melihat survey
                // yang sudah ditugaskan ke dirinya sendiri.
                return $query->where('surveys.surveyor_id', $user->id)
                    ->where(function ($visible) use ($user) {
                        $visible->whereHas('account', fn ($account) => $account->whereIn('account_group', [$user->survey_team, \App\Support\AccountGroup::TEAM_F]))
                            ->orWhereHas('loanApprovals', fn ($loan) => $loan->where('surveyor_id', $user->id)->active());
                    });
            }
            return $query->whereHas('account', fn ($account) => $account->where('account_group', $user->survey_team));
        }
        if ($user->isAdmin()) {
            return $query->where('surveys.account_id', $user->account_id)->where('surveys.requested_by', $user->id);
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

    public function loanApprovals()
    {
        return $this->hasMany(SurveyLoanApproval::class);
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
                    $targetStatusId = \App\Models\StatusCategory::whereRaw('LOWER(name) = ?', [strtolower($targetStatusName)])->value('id');
                    if ($targetStatusId) {
                        $consultation->status_category_id = $targetStatusId;
                        $consultation->save();

                        // Invalidate cache
                        try {
                            $superAdmins = \App\Models\User::where('role', \App\Enums\UserRole::SuperAdmin)->pluck('id');
                            foreach ($superAdmins as $adminId) {
                                \Illuminate\Support\Facades\Cache::forget("dashboard:super_admin:{$adminId}");
                            }
                        } catch (\Throwable $e) {}
                        if ($consultation->account_id) {
                            \Illuminate\Support\Facades\Cache::forget("dashboard:admin:{$consultation->account_id}");
                        }
                        \Illuminate\Support\Facades\Cache::forever('analytics:last_updated', now()->timestamp);
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
