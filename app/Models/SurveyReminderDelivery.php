<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris = satu pengingat logis untuk satu (survey, versi penugasan,
 * penerima). notification_id mengikat hasil in-app persisten supaya retry
 * tidak pernah membuat notifikasi kedua untuk delivery yang sama.
 */
class SurveyReminderDelivery extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_NOTIFIED = 'notified';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';

    public const PUSH_NOT_ATTEMPTED = 'not_attempted';
    public const PUSH_SKIPPED_NO_SUBSCRIPTION = 'skipped_no_subscription';
    public const PUSH_ATTEMPTED = 'attempted';
    public const PUSH_FAILED = 'failed';
    public const PUSH_UNKNOWN = 'unknown';

    protected $fillable = [
        'survey_id',
        'schedule_revision',
        'recipient_id',
        'scheduled_at_snapshot',
        'lead_minutes',
        'due_at',
        'status',
        'claimed_at',
        'lease_token',
        'attempts',
        'notification_id',
        'push_attempted_at',
        'push_status',
        'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at_snapshot' => 'datetime',
            'due_at' => 'datetime',
            'claimed_at' => 'datetime',
            'push_attempted_at' => 'datetime',
            'attempts' => 'integer',
            'schedule_revision' => 'integer',
        ];
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id')->withTrashed();
    }

    public function notification()
    {
        return $this->belongsTo(SurveyNotification::class, 'notification_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->pending()->where('due_at', '<=', now());
    }
}
